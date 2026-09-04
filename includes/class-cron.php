<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Agenda e corre a verificação em lotes pequenos.
 *
 * A razão de existir desta classe é uma só: NÃO REBENTAR O SITE DO CLIENTE.
 * Um varrimento de todos os links do site, corrido de uma vez em linha com o
 * pedido HTTP, satura um alojamento partilhado comum — é exactamente o que
 * este desenho evita, ao dividir o trabalho em lotes pequenos e em fundo.
 */
class Cron {

    const HOOK = 'onsite_link_verdict_run_batch';

    /** Hook do evento único que varre um artigo em segundo plano, longe do save_post. */
    const HOOK_SCAN_POST = 'onsite_link_verdict_scan_post';

    /**
     * Hook do evento único que coloca o site inteiro em fila para verificação.
     *
     * Acionado pelo botão "Iniciar varrimento" do painel — nunca corre em linha
     * com o pedido HTTP do próprio painel, pela mesma razão do orçamento de
     * tempo acima: um varrimento completo pode demorar minutos.
     */
    const HOOK_QUEUE_ALL = 'onsite_link_verdict_queue_all';

    /** Hook do evento único que varre um comentário em segundo plano, longe do comment_post e transition_comment_status. */
    const HOOK_SCAN_COMMENT = 'onsite_link_verdict_scan_comment';

    /**
     * Links verificados por passagem, por omissão.
     *
     * Era 20, e estava sete vezes mais apertado do que a própria proteção do
     * plugin. Medido a 18/08/2026: uma passagem de 20 links gastou **0,10 s** de
     * um orçamento de 45 s — dois décimos de um por cento. O travão a sério é o
     * TIME_BUDGET, que nunca chegava a ser usado; o 20 só atrasava o utilizador
     * sem poupar nada ao servidor.
     *
     * O que os 20 custavam: 20 links/hora = 480/dia, e como cada link volta à
     * fila ao fim de RECHECK_AFTER_HOURS, um site acima de ~3.360 links nunca
     * fechava um ciclo — a fila crescia mais depressa do que era servida. E
     * 3.360 links são uns 400 artigos com 8 links: um site de empresa normal,
     * precisamente o perfil de quem compraria a versão Pro.
     *
     * A 100, e com o orçamento de tempo a travar de facto, um site pequeno fica
     * pronto em minutos em vez de horas. O pior caso continua limitado pelo
     * relógio: uma passagem nunca passa de TIME_BUDGET segundos, seja qual for
     * este número.
     */
    const BATCH_DEFAULT = 100;

    /**
     * Teto absoluto, mesmo que alguém meta um número disparatado nas opções.
     *
     * Subiu de 200 para 1.000 a 21/08/2026, que é o lote do nível mais rápido.
     * Medido: 100 linhas custam 2,5 s, portanto 1.000 custam uns 25 s — dentro do
     * orçamento de 45 s, que continua a ser quem trava de facto.
     */
    const BATCH_MAX = 1000;

    /**
     * Segundos de relógio por omissão para uma passagem do lote.
     *
     * Uma única verificação pode, no pior caso (Checker::MAX_REDIRECTS saltos,
     * cada um a tentar HEAD e depois GET, cada pedido até Checker::TIMEOUT
     * segundos) demorar quase 144 segundos. Vinte links assim em série prendiam
     * o cron do cliente durante quase uma hora. Este orçamento é o que impede isso:
     * quando o relógio esgota, o lote para e o resto fica para a passagem seguinte.
     */
    const TIME_BUDGET_DEFAULT = 45;

    /** Teto absoluto do orçamento de tempo, mesmo que alguém meta um número disparatado. */
    const TIME_BUDGET_MAX = 300;

    /**
     * ── Os três níveis de velocidade ─────────────────────────────────────────
     *
     * Medido a 21/08/2026, num site de 2.832 artigos em MySQL: a verificação
     * andava a **100 linhas por hora**, o que dá **doze dias** até à primeira
     * imagem completa num site de dez mil artigos. Ninguém espera doze dias,
     * daí a necessidade destes três níveis de velocidade.
     *
     * A trava que protege o alojamento **não é** o número de linhas: é o
     * TIME_BUDGET. Medido três vezes seguidas: cem linhas custam **2,5 s** dos 45
     * permitidos — cinco por cento. Havia folga de sobra, e o orçamento continua
     * a valer para o pior caso: se os links forem lentos, a passagem pára aos 45
     * segundos e o resto fica na fila.
     *
     * 🔴 **O que se paga ao acelerar não é só carga do servidor — é qualidade da
     * resposta.** Quanto mais depressa se bate nos servidores dos outros, mais
     * deles começam a recusar, e um servidor que recusa manda o link para «não
     * consegui verificar». Correr mais depressa pode dar uma lista PIOR. É por
     * isso que a escolha é do dono do site e o aviso vai ao lado dela, em vez de
     * se acelerar tudo por omissão e ficar por dizer.
     */
    const SPEED_OPTION = 'onsite_link_verdict_speed';

    const SPEED_GENTLE = 'gentle';
    const SPEED_NORMAL = 'normal';
    const SPEED_FAST   = 'fast';

    /** Nomes dos intervalos que este plugin acrescenta ao WordPress. */
    const RECURRENCE_15MIN = 'onsite_link_verdict_15min';
    const RECURRENCE_5MIN  = 'onsite_link_verdict_5min';

    /**
     * O que cada nível faz, em números.
     *
     * @return array
     */
    public static function speeds() {
        return array(
            self::SPEED_GENTLE => array(
                'batch'      => 100,
                'recurrence' => 'hourly',
                'seconds'    => HOUR_IN_SECONDS,
            ),
            self::SPEED_NORMAL => array(
                'batch'      => 400,
                'recurrence' => self::RECURRENCE_15MIN,
                'seconds'    => 15 * MINUTE_IN_SECONDS,
            ),
            self::SPEED_FAST => array(
                'batch'      => 1000,
                'recurrence' => self::RECURRENCE_5MIN,
                'seconds'    => 5 * MINUTE_IN_SECONDS,
            ),
        );
    }

    /**
     * Acrescenta ao WordPress os intervalos de que os níveis precisam.
     *
     * 🚨 Sem isto, o wp_schedule_event() recusa um intervalo que não conhece
     * **em silêncio** — e o plugin ficava sem agendamento nenhum, a não verificar
     * nada, sem um erro. Pendura-se no cron_schedules a partir do ficheiro
     * principal, para valer também nos pedidos de cron.
     *
     * @param array $intervalos Os que já existem.
     * @return array
     */
    public static function register_schedules( $intervalos ) {

        if ( ! is_array( $intervalos ) ) {
            $intervalos = array();
        }

        $intervalos[ self::RECURRENCE_15MIN ] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 15 minutes (Onsite Link Verdict)', 'onsite-link-verdict' ),
        );

        $intervalos[ self::RECURRENCE_5MIN ] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 5 minutes (Onsite Link Verdict)', 'onsite-link-verdict' ),
        );

        return $intervalos;
    }

    /**
     * O nível escolhido, com a desconfiança que uma opção merece.
     *
     * Uma migração, um optimizador de base de dados ou alguém a editar à mão
     * podem deixar ali seja o que for. O que não é um dos três volta ao padrão.
     *
     * @return string
     */
    public function speed() {

        $guardado = get_option( self::SPEED_OPTION, self::SPEED_NORMAL );

        if ( ! is_string( $guardado ) || ! isset( self::speeds()[ $guardado ] ) ) {
            return self::SPEED_NORMAL;
        }

        return $guardado;
    }

    /**
     * Os números do nível actual.
     *
     * @return array
     */
    public function speed_settings() {
        return self::speeds()[ $this->speed() ];
    }

    /**
     * Quantas horas, no máximo, para verificar este número de linhas a este nível.
     *
     * É a conta que o painel mostra ao lado de cada nível, com os números do site
     * de quem está a ler — é isso que torna a escolha concreta em vez de abstracta.
     *
     * @param int    $linhas Linhas por verificar.
     * @param string $nivel  Um dos três níveis.
     * @return int Horas, arredondadas para cima.
     */
    public static function hours_for( $linhas, $nivel ) {

        $linhas = max( 0, (int) $linhas );

        if ( 0 === $linhas ) {
            return 0;
        }

        $niveis = self::speeds();
        $nivel  = isset( $niveis[ $nivel ] ) ? $nivel : self::SPEED_NORMAL;

        $por_hora = ( $niveis[ $nivel ]['batch'] * HOUR_IN_SECONDS ) / $niveis[ $nivel ]['seconds'];

        return (int) ceil( $linhas / $por_hora );
    }

    /** @var Store */
    private $store;

    /** @var Checker */
    private $checker;

    /**
     * Opção que serve de fecho contra passagens simultâneas.
     *
     * O `wp-cron` do WordPress dispara em pedidos de visitantes, e o fecho dele
     * é conhecido por ter frestas. Sem um fecho nosso, duas passagens ao mesmo
     * tempo consultam a fila antes de qualquer uma gravar resultados, apanham as
     * MESMAS linhas, e fazem os mesmos pedidos HTTP ao servidor de outra pessoa.
     * Medido a 19/08/2026: duas chamadas seguidas ao `get_due_for_check()`
     * devolveram exactamente as mesmas 5 linhas.
     *
     * Duas passagens em paralelo duplicam pedidos HTTP a servidores de
     * terceiros sem necessidade nenhuma — é carga desnecessária num servidor
     * que não é nosso, e o fecho evita-a de raiz.
     */
    const LOCK_OPTION = 'onsite_link_verdict_batch_lock';

    /**
     * Segundos ao fim dos quais um fecho esquecido pode ser roubado.
     *
     * Se um processo morrer a meio — tempo esgotado, memória, o alojamento a
     * matar o pedido —, o fecho ficaria posto e o plugin parava em silêncio, que
     * é a pior maneira de parar. Folgado em relação ao TIME_BUDGET_MAX (300s)
     * para nunca roubar o fecho a uma passagem que ainda está viva.
     */
    const LOCK_TTL = 600;

    /** Fecho do varrimento completo, que se reagenda a si próprio e também se pode sobrepor. */
    const LOCK_SCAN_OPTION = 'onsite_link_verdict_scan_lock';

    public function __construct( $store = null, $checker = null ) {
        $this->store   = $store ? $store : new Store();
        $this->checker = $checker ? $checker : new Checker();
    }

    /**
     * Agenda a verificação com o intervalo do nível escolhido.
     *
     * 🔴 **Reagendar quando o nível muda é metade do trabalho, e é a metade que
     * falha em silêncio.** Escolher «rápido» escreve uma opção; se o evento
     * antigo ficasse de pé, o utilizador via a promessa de horas em vez de dias e
     * o plugin continuava a correr de hora a hora — sem erro e sem aviso. Há um
     * teste que recusa isso.
     *
     * Sem mudança de nível não se toca na hora marcada: isto corre a cada página
     * do painel (admin_init), e reagendar de cada vez empurrava a verificação
     * para a frente para sempre.
     *
     * @return void
     */
    public function schedule() {

        $nivel    = $this->speed_settings();
        $agendado = wp_get_scheduled_event( self::HOOK );

        if ( $agendado && $agendado->schedule === $nivel['recurrence'] ) {
            return;
        }

        if ( $agendado ) {
            wp_unschedule_hook( self::HOOK );
        }

        wp_schedule_event( time() + $nivel['seconds'], $nivel['recurrence'], self::HOOK );
    }

    public function unschedule() {
        wp_unschedule_hook( self::HOOK );
        wp_unschedule_hook( self::HOOK_SCAN_POST );
        wp_unschedule_hook( self::HOOK_QUEUE_ALL );
        wp_unschedule_hook( self::HOOK_SCAN_COMMENT );
    }

    /**
     * Quantas linhas por passagem.
     *
     * Vem do nível escolhido. A opção manual continua a mandar quando existe:
     * está documentada desde o início e há quem a tenha posto — mudar os níveis
     * não pode desfazer uma escolha que alguém já fez à mão.
     *
     * @return int
     */
    public function batch_size() {

        if ( false !== get_option( 'onsite_link_verdict_batch_size', false ) ) {
            return $this->numero_da_opcao( 'onsite_link_verdict_batch_size', self::BATCH_DEFAULT, self::BATCH_MAX );
        }

        return $this->speed_settings()['batch'];
    }

    /**
     * Um número vindo de uma opção, com o mínimo de desconfiança que merece.
     *
     * As opções são números, mas quem lá escreve nem sempre é o plugin: uma
     * migração mal feita, um "optimizador" de base de dados, alguém a editar à
     * mão. Um valor **não escalar** — um array — passava pelo `(int)` e dava
     * **1**: lote de um link e orçamento de um segundo. O plugin não rebentava,
     * ficava só a não fazer nada, que é a pior maneira de parar.
     *
     * Só se rejeita o que é claramente corrupção. Um número que alguém escolheu
     * de propósito respeita-se, mesmo que seja pequeno — não se anda a adivinhar
     * por cima de quem sabe o que está a fazer.
     *
     * @param string $opcao   Nome da opção.
     * @param int    $omissao Valor por omissão.
     * @param int    $teto    Máximo aceite.
     * @return int
     */
    private function numero_da_opcao( $opcao, $omissao, $teto ) {

        $bruto = get_option( $opcao, $omissao );

        if ( ! is_scalar( $bruto ) || ! is_numeric( $bruto ) ) {
            return $omissao;
        }

        $valor = (int) $bruto;

        if ( $valor < 1 ) {
            return $omissao;
        }

        return min( $valor, $teto );
    }

    /**
     * Orçamento de tempo de relógio (em segundos) para uma passagem do lote.
     * Mesmo saneamento do batch_size(): valor inválido volta ao padrão, e há
     * sempre um teto absoluto.
     */
    public function time_budget() {
        return $this->numero_da_opcao( 'onsite_link_verdict_time_budget', self::TIME_BUDGET_DEFAULT, self::TIME_BUDGET_MAX );
    }

    /**
     * @return int Número de links verificados nesta passagem.
     */
    /**
     * Tenta fechar a porta. Devolve false se outra passagem já a fechou.
     *
     * O `add_option()` é usado de propósito em vez de `get`+`set`: é um único
     * INSERT, e o índice único do nome da opção faz o trabalho de arbitragem na
     * própria base de dados. Um `get` seguido de `set` deixava a fresta aberta
     * entre os dois — que é exactamente o problema que isto vem resolver.
     *
     * @return bool
     */
    public function lock() {
        return $this->fechar( self::LOCK_OPTION );
    }

    /**
     * @return void
     */
    public function unlock() {
        delete_option( self::LOCK_OPTION );
    }

    /**
     * O mesmo, para o varrimento completo.
     *
     * @return bool
     */
    public function lock_scan() {
        return $this->fechar( self::LOCK_SCAN_OPTION );
    }

    /**
     * @return void
     */
    public function unlock_scan() {
        delete_option( self::LOCK_SCAN_OPTION );
    }

    /**
     * A mecânica do fecho, partilhada pelos dois.
     *
     * @param string $opcao Opção que serve de fecho.
     * @return bool
     */
    private function fechar( $opcao ) {

        global $wpdb;

        $agora = time();

        if ( add_option( $opcao, $agora, '', false ) ) {
            return true;
        }

        /*
         * A porta ja estava fechada. So resta o caminho de RECUPERACAO: roubar
         * um fecho que ficou para tras porque o processo que o pos morreu — o
         * alojamento a cortar o pedido, a memoria a acabar, o que for.
         *
         * 🔴 Isto era um `get_option()` seguido de um `update_option()`, e era
         * uma corrida — apanhada pela revisao do WordPress.org a 26/08/2026.
         * Dois processos que lessem o mesmo valor expirado escreviam os dois e
         * seguiam os dois em frente. Ou seja: a fresta que o `add_option()` acima
         * existe para fechar estava aberta trinta linhas abaixo, e a promessa que
         * o docblock do lock() faz por escrito valia so metade.
         *
         * A correccao e a troca condicional de sempre: le-se o valor EM BRUTO, e
         * a escrita so acontece se a linha AINDA tiver esse mesmo valor. Quem
         * chegar depois encontra outro valor la, leva zero linhas afectadas e
         * percebe que perdeu. Quem arbitra e a base de dados, que e a unica que
         * pode.
         *
         * ⚠️ Chegou a estar aqui, mais curto, um `... AND option_value + 0 < %d`
         * — a condicao de expiracao dentro do proprio UPDATE, numa instrucao so.
         * Foi trocado de propósito: essa versao obriga o MySQL a coagir a texto o
         * valor guardado, e num alojamento em `STRICT_ALL_TABLES` nao esta
         * garantido que o aviso de coercao continue a ser so um aviso. Nao vale a
         * pena poupar uma leitura em troca de uma duvida sobre o que acontece no
         * servidor de outra pessoa.
         *
         * 🪤 E le-se com get_row() e nao com get_var(), que era o obvio: o
         * get_var() devolve NULL para uma linha AUSENTE e NULL para uma linha com
         * valor VAZIO — dois casos opostos com a mesma resposta. Tratar os dois
         * como "nao ha nada" deixava um fecho de valor vazio por roubar para
         * sempre. Medido a 27/08/2026, com um teste que ficou vermelho.
         */
        $linha = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $opcao
            ),
            ARRAY_A
        );

        if ( null === $linha ) {
            // A linha desapareceu mesmo, entre o add_option() e esta leitura.
            // Fica para a passagem seguinte, que volta a tentar pelo caminho
            // atomico do add_option().
            return false;
        }

        $posto = (string) $linha['option_value'];

        /*
         * Um valor ilegivel conta como zero, ou seja, como fecho expirado.
         *
         * E de propósito e e o lado seguro: o que la esta nem sempre foi escrito
         * por nos — uma migracao mal feita, um "optimizador" de base de dados,
         * uma restauracao. Ler lixo como um fecho valido e recente trancava o
         * plugin para sempre, em silencio, que e o pior modo de falha que este
         * produto tem.
         */
        if ( (int) $posto > 0 && ( $agora - (int) $posto ) < self::LOCK_TTL ) {
            return false;
        }

        $roubado = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                (string) $agora,
                $opcao,
                $posto
            )
        );

        /*
         * A escrita foi directa a tabela, por isso a cache de opcoes ficou a
         * mentir — quem a lesse a seguir via o fecho velho.
         *
         * Sao precisas as DUAS limpezas, e a segunda nao e zelo a mais: uma
         * opcao marcada para carregamento automatico nao vive na sua propria
         * entrada de cache, vive dentro do bloco `alloptions`, e a primeira linha
         * nao lhe toca. Nos criamos este fecho sem carregamento automatico, mas a
         * linha pode ter ficado de outra maneira — uma versao antiga, uma
         * migracao, uma ferramenta de base de dados. Apanhado por um teste a
         * 27/08/2026, que dava o fecho por roubado com o valor velho ainda a ser
         * lido a seguir.
         */
        wp_cache_delete( $opcao, 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        return 1 === (int) $roubado;
    }

    public function run_batch() {

        /*
         * A tabela tem de existir antes de o cron a usar.
         *
         * Num subsite de multisite o register_activation_hook só corre para o
         * site principal, e o maybe_upgrade() só corre no `admin_init` — ou
         * seja, só quando alguém lá abre o wp-admin. Mas o cron corre na mesma.
         * Sem isto, um subsite onde ninguém entre no painel fica a dar erro de
         * base de dados de hora a hora, para sempre: exactamente o "plugin a
         * martelar em silêncio" contra o qual este produto se posiciona. Vale
         * também para uma restauração de base de dados mal feita.
         *
         * Custa uma leitura de opção por passagem do cron.
         */
        $this->store->maybe_upgrade( defined( 'ONSITE_LINK_VERDICT_VERSION' ) ? ONSITE_LINK_VERDICT_VERSION : 'dev' );

        if ( ! $this->lock() ) {
            return 0;
        }

        try {
            return $this->correr_lote();
        } finally {
            // O `finally` não é enfeite: sem ele, uma passagem que rebente deixa
            // a porta fechada e o plugin para em silêncio até o TTL expirar.
            $this->unlock();
        }
    }

    /**
     * O trabalho da passagem, já com a porta fechada.
     *
     * @return int
     */
    private function correr_lote() {

        $pendentes = $this->store->get_due_for_check( $this->batch_size() );

        if ( empty( $pendentes ) ) {
            return 0;
        }

        $inicio    = microtime( true );
        $orcamento = $this->time_budget();
        $feitos    = 0;

        /*
         * O MESMO endereço só se pede UMA vez por passagem.
         *
         * A tabela guarda uma linha por (artigo, URL), porque o utilizador tem
         * de saber em que artigos está cada link partido. Mas isso fazia com que
         * um link presente em 400 artigos gerasse 400 pedidos HTTP ao servidor
         * de destino — para descobrir 400 vezes a mesma coisa. Medido a
         * 18/08/2026: 25 linhas com o mesmo URL davam 20 pedidos numa passagem.
         *
         * Repetir 400 vezes o mesmo pedido a um servidor alheio é carga
         * desnecessária nesse servidor, só para chegar 400 vezes à mesma
         * resposta.
         *
         * Guarda-se o resultado por URL e reaproveita-se. As linhas continuam a
         * ser todas actualizadas (a série de falhas é por linha), mas a parte
         * cara — o pedido — faz-se uma vez.
         */
        $ja_verificados = array();

        foreach ( $pendentes as $linha ) {

            $url = $linha['url'];

            // Antes de cada verificação NOVA, confirma-se se já passámos o
            // orçamento de tempo. Os links que ficarem por fazer continuam na
            // fila e são apanhados na passagem seguinte — nada se perde.
            // Um URL já verificado nesta passagem não custa tempo nenhum, por
            // isso não tropeça no orçamento.
            if ( ! isset( $ja_verificados[ $url ] ) ) {
                if ( ( microtime( true ) - $inicio ) >= $orcamento ) {
                    break;
                }
                $ja_verificados[ $url ] = $this->checker->check( $url );
            }

            $this->store->update_result( (int) $linha['id'], $ja_verificados[ $url ] );
            $feitos++;
        }

        return $feitos;
    }

    /**
     * Agenda a passagem seguinte do varrimento completo.
     *
     * O Scanner::queue_all() faz uma fatia do trabalho e chama isto enquanto
     * sobrar conteúdo por varrer. O intervalo é de um minuto: o varrimento é a
     * operação mais pesada do plugin e não tem pressa nenhuma — a fila vai sendo
     * verificada em paralelo pelo lote horário.
     */
    public function schedule_queue_all_continuation() {
        if ( ! wp_next_scheduled( self::HOOK_QUEUE_ALL ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK_QUEUE_ALL );
        }
    }

    /**
     * O wp-cron do WordPress só dispara quando alguém visita o site. Num site de pouco
     * tráfego, a verificação nunca corre — e o utilizador acha que o plugin está partido.
     * Se DISABLE_WP_CRON estiver ligado sem um cron de sistema, avisamos no painel.
     */
    public function is_real_cron_disabled() {
        return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    }
}
