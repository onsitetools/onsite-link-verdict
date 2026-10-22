<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * O painel. Mostra o que se encontrou, por estado, em linguagem que se percebe.
 *
 * Um número HTTP nu ("403") não ajuda ninguém. A coluna explica o que aquilo quer
 * dizer e se é preciso fazer alguma coisa.
 *
 * Nenhuma ação daqui verifica links no próprio pedido HTTP — uma única
 * verificação pode levar quase 144s no pior caso (ver o comentário do
 * Cron::TIME_BUDGET_DEFAULT), e isso estoura o max_execution_time de
 * qualquer alojamento partilhado. As duas ações do painel (o botão "Iniciar
 * varrimento" e o "Verificar de novo" por linha) AGENDAM em segundo plano.
 */
class Admin_Page {

    const MENU_SLUG = 'onsite-link-verdict';

    /** Nome da ação de "Verificar de novo" — também serve de prefixo do nonce (mais o id). */
    const ACTION_RECHECK = 'onsite_link_verdict_recheck';

    /** Guardar as regras de exclusão (versão Pro). */
    const ACTION_EXCLUSIONS = 'onsite_link_verdict_exclusions_save';

    /** Exportar a lista para CSV (versão Pro). Tratada no admin_post, não aqui. */
    const ACTION_EXPORT = 'onsite_link_verdict_export';

    /** Gravar o nível de velocidade da verificação. */
    const ACTION_SPEED = 'onsite_link_verdict_speed_save';

    /** @var Store */
    private $store;

    public function __construct( $store = null ) {
        $this->store = $store ? $store : new Store();
    }

    public function register() {
        add_management_page(
            __( 'Onsite Link Verdict', 'onsite-link-verdict' ),
            __( 'Onsite Link Verdict', 'onsite-link-verdict' ),
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render' )
        );
    }

    /**
     * @return string Estado a mostrar. Nunca devolve nada que não seja um dos permitidos.
     */
    public function current_tab() {

        $permitidos = array(
            Response_Classifier::BROKEN,
            Response_Classifier::WARNING,
            Response_Classifier::UNVERIFIABLE,
            Response_Classifier::OK,
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só leitura, não muda estado.
        $pedido = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

        return in_array( $pedido, $permitidos, true ) ? $pedido : Response_Classifier::BROKEN;
    }

    /**
     * Traduz um resultado técnico em português corrente.
     */
    public function explain( $http_code, $error, $status = '', $url = '' ) {

        /*
         * Quando o veredito e "nao consegui verificar" e o servidor RESPONDEU,
         * a legenda tem de explicar o veredito, nao so o codigo. Sem isto o
         * utilizador ve um 503 no separador "nao consegui verificar" com a
         * legenda "servico temporariamente indisponivel" e conclui, com toda a
         * razao, que o plugin se contradiz.
         *
         * E este o caso que a Amazon produz aos milhares: responde 503 a robos,
         * sempre, e o link esta perfeitamente vivo para quem la vai de browser.
         */
        if ( Response_Classifier::UNVERIFIABLE === $status && null !== $http_code && (int) $http_code >= 500 ) {
            /* translators: %d: codigo HTTP devolvido pelo servidor. */
            return sprintf( __( '%d — the server answered this every time we asked. It is refusing automated checks, not telling us the page is gone. Open it in your browser to confirm.', 'onsite-link-verdict' ), (int) $http_code );
        }

        if ( '' !== (string) $error ) {
            $erros = array(
                'dns'                => __( 'The domain no longer exists.', 'onsite-link-verdict' ),
                'timeout'            => __( 'The server took too long to respond.', 'onsite-link-verdict' ),
                'ssl'                => __( "The destination's security certificate has problems.", 'onsite-link-verdict' ),
                'connection'         => __( 'Could not connect to the server.', 'onsite-link-verdict' ),
                'redirect_loop'      => __( 'The redirects go in circles and never arrive anywhere.', 'onsite-link-verdict' ),
                'too_many_redirects' => __( 'Too many redirects in a chain.', 'onsite-link-verdict' ),
            );

            return isset( $erros[ $error ] ) ? $erros[ $error ] : __( 'Could not check this link.', 'onsite-link-verdict' );
        }

        /*
         * Um 404 num link do PRÓPRIO site pode não ser o que parece.
         *
         * Uma página em rascunho, privada ou pendente devolve 404 ao visitante —
         * e nós dizíamos "a página já não existe". O dono abre-a no editor,
         * vê-a lá, e conclui que o plugin mente. Para links internos não há
         * desculpa para este engano: a base de dados está aqui ao lado.
         *
         * O veredito continua a ser PARTIDO, e bem: o visitante leva mesmo um
         * 404, e isso é um problema para resolver. O que muda é a explicação.
         *
         * Só se procura quando vale a pena — 404/410, endereço do próprio site —
         * para não se pagar uma consulta por linha numa lista de links externos.
         */
        $nao_publicado = $this->estado_nao_publico( $url, $http_code );

        if ( '' !== $nao_publicado ) {
            return sprintf(
                /* translators: %s: estado do conteúdo no WordPress (draft, private, pending). */
                __( 'This page is on your site but is not published (status: %s), so visitors get a 404. Publish it, or point the link somewhere else.', 'onsite-link-verdict' ),
                $nao_publicado
            );
        }

        $codigos = array(
            404 => __( '404 — the page no longer exists.', 'onsite-link-verdict' ),
            410 => __( '410 — the page was permanently removed.', 'onsite-link-verdict' ),
            403 => __( '403 — the server blocks automated checks. The link is probably fine; open it in your browser to confirm.', 'onsite-link-verdict' ),
            401 => __( '401 — the page requires authentication.', 'onsite-link-verdict' ),
            429 => __( '429 — we were asked to slow down. We will try again later.', 'onsite-link-verdict' ),
            451 => __( '451 — blocked for legal reasons.', 'onsite-link-verdict' ),
            999 => __( '999 — a response some sites (such as LinkedIn) give to bots. This is not a broken link.', 'onsite-link-verdict' ),
            500 => __( '500 — error on the destination server. This may be temporary.', 'onsite-link-verdict' ),
            502 => __( '502 — the destination server is having problems. This may be temporary.', 'onsite-link-verdict' ),
            503 => __( '503 — service temporarily unavailable.', 'onsite-link-verdict' ),
        );

        if ( isset( $codigos[ (int) $http_code ] ) ) {
            return $codigos[ (int) $http_code ];
        }

        if ( null === $http_code ) {
            return __( 'Not checked yet.', 'onsite-link-verdict' );
        }

        /* translators: %d: código HTTP devolvido pelo servidor. */
        return sprintf( __( 'The server responded %d.', 'onsite-link-verdict' ), (int) $http_code );
    }

    /**
     * O endereço aponta para conteúdo deste site que existe mas não está público?
     *
     * @param string   $url       Endereço.
     * @param int|null $http_code Código devolvido.
     * @return string Estado do conteúdo ('draft', 'private', 'pending'), ou '' se não é o caso.
     */
    private function estado_nao_publico( $url, $http_code ) {

        $url = trim( (string) $url );

        if ( '' === $url || ! in_array( (int) $http_code, array( 404, 410 ), true ) ) {
            return '';
        }

        $meu  = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $dele = wp_parse_url( $url, PHP_URL_HOST );

        if ( ! $meu || ! $dele || strtolower( $meu ) !== strtolower( $dele ) ) {
            return '';
        }

        $post_id = url_to_postid( $url );

        if ( ! $post_id ) {
            return '';
        }

        $estado = get_post_status( $post_id );

        // Os estados públicos não interessam: se a página está publicada e mesmo
        // assim deu 404, há outra coisa em jogo e não somos nós a adivinhá-la.
        if ( ! $estado || in_array( $estado, get_post_stati( array( 'public' => true ) ), true ) ) {
            return '';
        }

        return $estado;
    }

    /**
     * Trata as duas ações do painel, se alguma vier no pedido. Corre sempre
     * antes de desenhar HTML nenhum — assim uma ação bem sucedida já mostra
     * a mensagem na mesma visita.
     *
     * Falha em silêncio (devolve null, não altera nada) quando o nonce não
     * bate certo ou falta a capacidade — nunca um wp_die() aqui, para o
     * pedido continuar e a página se desenhar na mesma, só sem efeito.
     *
     * @return string|null Mensagem para mostrar ao utilizador, ou null se não houve ação.
     */
    public function handle_actions() {

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- o nonce é verificado dentro de cada handler.
        if ( isset( $_POST['onsite_link_verdict_action'] ) && Cron::HOOK_QUEUE_ALL === $_POST['onsite_link_verdict_action'] ) {
            return $this->handle_queue_all();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- o nonce é verificado dentro do handler.
        if ( isset( $_POST['onsite_link_verdict_action'] ) && self::ACTION_EXCLUSIONS === $_POST['onsite_link_verdict_action'] ) {
            return $this->handle_exclusions();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- o nonce é verificado dentro do handler.
        if ( isset( $_POST['onsite_link_verdict_action'] ) && self::ACTION_SPEED === $_POST['onsite_link_verdict_action'] ) {
            return $this->handle_speed();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- o nonce é verificado dentro do handler.
        if ( isset( $_GET['onsite_link_verdict_action'] ) && self::ACTION_RECHECK === $_GET['onsite_link_verdict_action'] ) {
            return $this->handle_recheck();
        }

        return null;
    }

    /**
     * Guarda o nível de velocidade da verificação.
     *
     * 🔴 **Gravar a opção é metade do trabalho.** O intervalo do cron está preso
     * ao nível, e sem o `schedule()` a seguir o utilizador escolhia «rápido», via
     * a promessa de horas, e o plugin continuava a correr de hora a hora — sem
     * erro nenhum. É a razão de o `Cron::schedule()` ser chamado aqui e não
     * ficar à espera do próximo `admin_init`.
     *
     * Isto **não** é da versão paga: uma versão gratuita deliberadamente lenta é
     * exactamente a leitura que a regra 5 do repositório não quer.
     *
     * @return string|null Mensagem para o utilizador, ou null se não fez nada.
     */
    private function handle_speed() {

        if ( ! current_user_can( 'manage_options' ) ) {
            return null;
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, self::ACTION_SPEED ) ) {
            return null;
        }

        $pedido = isset( $_POST['speed'] ) ? sanitize_key( wp_unslash( $_POST['speed'] ) ) : '';

        if ( ! isset( Cron::speeds()[ $pedido ] ) ) {
            return null;
        }

        update_option( Cron::SPEED_OPTION, $pedido, false );

        // O agendamento vive preso ao nível. Sem isto, a escolha não faz nada.
        ( new Cron( $this->store ) )->schedule();

        $pendentes = (int) $this->store->count_by_status()['pending'];
        $horas     = Cron::hours_for( $pendentes, $pedido );

        if ( $horas < 1 ) {
            return __( 'Speed saved.', 'onsite-link-verdict' );
        }

        return sprintf(
            /* translators: %s: número de horas. */
            __( 'Speed saved. At this pace the queue takes about %s.', 'onsite-link-verdict' ),
            self::humanizar_horas( $horas )
        );
    }

    /**
     * Horas em linguagem de gente: acima de um dia, diz-se em dias.
     *
     * @param int $horas Horas.
     * @return string
     */
    private static function humanizar_horas( $horas ) {

        $horas = (int) $horas;

        if ( $horas < 24 ) {
            return sprintf(
                /* translators: %s: número de horas. */
                _n( '%s hour', '%s hours', $horas, 'onsite-link-verdict' ),
                number_format_i18n( $horas )
            );
        }

        $dias = (int) ceil( $horas / 24 );

        return sprintf(
            /* translators: %s: número de dias. */
            _n( '%s day', '%s days', $dias, 'onsite-link-verdict' ),
            number_format_i18n( $dias )
        );
    }

    /**
     * O ecrã onde se escolhe a velocidade da verificação.
     *
     * ── Porque é que isto existe, e porque é que é uma escolha ────────────────
     *
     * Medido a 21/08/2026 num site de 2.832 artigos: à velocidade antiga, um site
     * de dez mil artigos levava **doze dias** até dar a primeira imagem completa.
     * Ninguém espera doze dias.
     *
     * Mas acelerar não é de graça, e o preço não é o que se espera: **não é só
     * carga no teu servidor, é qualidade da resposta.** Quanto mais depressa se
     * bate nos servidores dos outros, mais deles recusam — e um servidor que
     * recusa manda o link para «não consegui verificar». Por isso a escolha é de
     * quem tem o site, com o custo escrito ao lado, e não uma decisão nossa
     * tomada em silêncio por toda a gente.
     *
     * A conta que aparece em cada nível é feita com **os links deste site**. Um
     * número abstracto não ajuda ninguém a escolher; «com os teus 30.000 links,
     * cerca de 19 horas» ajuda.
     *
     * @return void
     */
    private function render_speed_box() {

        $cron      = new Cron( $this->store );
        $actual    = $cron->speed();
        $pendentes = (int) $this->store->count_by_status()['pending'];

        $rotulos = array(
            Cron::SPEED_GENTLE => array(
                __( 'Careful', 'onsite-link-verdict' ),
                __( 'The lightest setting. Use it on shared or slow hosting, or if your host has complained about background work before.', 'onsite-link-verdict' ),
            ),
            Cron::SPEED_NORMAL => array(
                __( 'Normal', 'onsite-link-verdict' ),
                __( 'The balance we recommend, and what most sites should stay on.', 'onsite-link-verdict' ),
            ),
            Cron::SPEED_FAST => array(
                __( 'Fast', 'onsite-link-verdict' ),
                __( 'Your host has to be able to take it — and so do the sites you link to. Some of them will start refusing the checks, which means more links end up in "Couldn\'t verify". Faster is not always better.', 'onsite-link-verdict' ),
            ),
        );

        echo '<div class="card" style="max-width:none;margin-top:1em;">';
        echo '<h2 style="margin-top:0;">' . esc_html__( 'How fast should it check?', 'onsite-link-verdict' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'Finding the links is quick. Checking whether they are alive is the slow part, and this is where you decide how much of your server it may use.', 'onsite-link-verdict' ) . '</p>';

        echo '<form method="post">';
        wp_nonce_field( self::ACTION_SPEED );
        echo '<input type="hidden" name="onsite_link_verdict_action" value="' . esc_attr( self::ACTION_SPEED ) . '" />';

        echo '<table class="form-table" role="presentation"><tbody>';

        foreach ( Cron::speeds() as $chave => $nivel ) {

            $horas = Cron::hours_for( $pendentes, $chave );

            echo '<tr><th scope="row" style="width:9em;">';
            echo '<label><input type="radio" name="speed" value="' . esc_attr( $chave ) . '" ' . checked( $chave, $actual, false ) . ' /> ';
            echo '<strong>' . esc_html( $rotulos[ $chave ][0] ) . '</strong></label>';
            echo '</th><td>';

            echo '<p style="margin:0 0 .3em;">' . esc_html( $rotulos[ $chave ][1] ) . '</p>';

            echo '<p class="description" style="margin:0;">';
            printf(
                /* translators: 1: número de links por hora. */
                esc_html__( 'Up to %1$s links an hour.', 'onsite-link-verdict' ),
                esc_html( number_format_i18n( ( $nivel['batch'] * HOUR_IN_SECONDS ) / $nivel['seconds'] ) )
            );

            if ( $horas > 0 ) {
                echo ' <strong>';
                printf(
                    /* translators: 1: número de links em fila, 2: tempo. */
                    esc_html__( 'With the %1$s links waiting on this site: about %2$s.', 'onsite-link-verdict' ),
                    esc_html( number_format_i18n( $pendentes ) ),
                    esc_html( self::humanizar_horas( $horas ) )
                );
                echo '</strong>';
            }

            echo '</p></td></tr>';
        }

        echo '</tbody></table>';

        echo '<p class="submit" style="margin:0;"><button type="submit" class="button button-secondary">' . esc_html__( 'Save speed', 'onsite-link-verdict' ) . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * Guarda as regras de exclusão. Versão Pro.
     *
     * @return string|null Mensagem para o utilizador, ou null se não fez nada.
     */
    private function handle_exclusions() {

        if ( ! current_user_can( 'manage_options' ) || ! Pro::is_active() ) {
            return null;
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, self::ACTION_EXCLUSIONS ) ) {
            return null;
        }

        /*
         * A limpeza e a `Sanitizer::address_patterns()` e NAO a
         * `sanitize_textarea_field()` do costume, de propósito: essa apaga as
         * sequencias `%XX`, e isto sao padroes de endereco, onde `%20` e
         * `%C3%A9` sao legitimos — corrompia a regra em silencio. A razao inteira
         * esta no docblock dela, e ha testes a guardar as duas metades.
         *
         * A `Sanitizer` vive em includes/class-sanitizer.php e vai nos DOIS
         * pacotes, ao contrario da `Exclusions`. Quem ler so o pacote gratuito
         * consegue conferir a limpeza inteira.
         */
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- limpo na linha seguinte pela Sanitizer::address_patterns(); a funcao do WordPress corromperia os padroes de endereco.
        $texto  = isset( $_POST['exclusions'] ) ? wp_unslash( $_POST['exclusions'] ) : '';
        $texto  = Sanitizer::address_patterns( $texto );
        $regras = Exclusions::save( $texto );

        return sprintf(
            /* translators: %d: número de regras guardadas. */
            _n( '%d rule saved. It takes effect as each post is checked again.', '%d rules saved. They take effect as each post is checked again.', count( $regras ), 'onsite-link-verdict' ),
            count( $regras )
        );
    }

    /**
     * Acrescento 1: botão "Iniciar varrimento". Agenda um único evento daqui a
     * 10s — não corre nada agora. Se já houver um agendado, não duplica.
     *
     * Agenda TAMBÉM uma passagem do lote, tal como o handle_recheck() já fazia.
     * Sem isso, o agendamento horário criado na ativação está marcado para
     * time() + HOUR_IN_SECONDS, e o primeiro lote só corria uma hora depois: o
     * primeiro uso real do plugin era carregar em "Start scan", ver "Scan
     * scheduled" e ficar uma hora a olhar para "Broken (0) — Nothing to show
     * here. Good news.".
     */
    private function handle_queue_all() {

        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, Cron::HOOK_QUEUE_ALL ) ) {
            return null;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return null;
        }

        // O mesmo pedido que a ability start-scan faz. Ver Cron::request_full_scan().
        ( new Cron() )->request_full_scan();

        return __( 'Scan scheduled. Links will be checked in the background.', 'onsite-link-verdict' );
    }

    /**
     * Acrescento 2: ação "Verificar de novo" por linha. Põe o link de volta à
     * frente da fila (Store::mark_for_recheck) e agenda uma única passagem do
     * lote normal, para o utilizador não ter de esperar pela hora seguinte.
     */
    private function handle_recheck() {

        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( $id <= 0 ) {
            return null;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, self::ACTION_RECHECK . '_' . $id ) ) {
            return null;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return null;
        }

        $this->store->mark_for_recheck( $id );

        $this->agendar_passagem_do_lote();

        return __( 'This link has been queued for a new check.', 'onsite-link-verdict' );
    }

    /**
     * Agenda uma passagem do lote para daqui a um minuto, para o utilizador não
     * esperar pela hora seguinte.
     *
     * Sem guarda de `wp_next_scheduled()`, de propósito. O evento horário existe
     * quase sempre (é criado na ativação e reposto no admin_init), e uma guarda
     * dessas fazia disto letra morta — era o que acontecia no handle_recheck().
     * Duplicados não são problema: o próprio wp_schedule_single_event() recusa um
     * agendamento a menos de 10 minutos de outro do mesmo hook, portanto carregar
     * cinco vezes no botão não enche a fila de eventos.
     */
    private function agendar_passagem_do_lote() {
        wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Cron::HOOK );
    }

    /**
     * A linha que diz quantos links estão à espera de ser verificados.
     *
     * O Store::record() grava tudo em 'pending', e os quatro separadores do painel
     * são broken/warning/unverifiable/ok — o 'pending' não tem separador nenhum,
     * por isso os links enfileirados eram simplesmente invisíveis. O primeiro uso
     * real do plugin era carregar em "Start scan", ver "Scan scheduled", abrir
     * "Broken (0)" e ler "Nothing to show here. Good news." — sem nunca haver um
     * número a dizer quanto faltava. Num site de 2.000 links a lista demorava dias
     * a ficar completa.
     *
     * Não é um separador de propósito: uma lista de links por verificar não tem
     * nada para mostrar (não há resultado nenhum), enquanto um número tem.
     *
     * @param int $pendentes Quantos links estão por verificar.
     * @return string HTML já escapado, ou '' se não há nada a dizer.
     */
    /**
     * Quantos faltam — e, se der, quanto tempo falta.
     *
     * A verificação corre em segundo plano e é deliberadamente contida, por isso
     * num site com muitos links pode levar horas. Sem esta linha, o utilizador
     * carrega em "Iniciar varrimento", vê "Partidos (0)" e "Nada para mostrar
     * aqui" — exactamente o mesmo ecrã que veria se o plugin estivesse avariado.
     * Desinstala e escreve que não funciona.
     *
     * O progresso não muda nada na carga do servidor e é o que separa "está
     * partido" de "está a trabalhar".
     *
     * @param int      $pendentes Links por verificar.
     * @param int|null $total     Total de links registados. Sem isto, só se diz quantos faltam.
     */
    public function pending_notice( $pendentes, $total = null ) {

        $pendentes = (int) $pendentes;

        if ( $pendentes < 1 ) {
            return '';
        }

        $texto = sprintf(
            /* translators: %s: número de links por verificar, já formatado. */
            _n(
                '%s link waiting to be checked.',
                '%s links waiting to be checked.',
                $pendentes,
                'onsite-link-verdict'
            ),
            number_format_i18n( $pendentes )
        );

        $total = (int) $total;

        if ( $total > 0 && $total >= $pendentes ) {
            $feitos = $total - $pendentes;

            $texto = sprintf(
                /* translators: 1: links já verificados, 2: total de links, 3: percentagem. */
                __( '%1$s of %2$s links checked (%3$s%%).', 'onsite-link-verdict' ),
                number_format_i18n( $feitos ),
                number_format_i18n( $total ),
                number_format_i18n( (int) floor( ( $feitos / $total ) * 100 ) )
            ) . ' ' . $texto;
        }

        $estimativa = $this->estimated_time_left( $pendentes );

        if ( '' !== $estimativa ) {
            $texto .= ' ' . $estimativa;
        }

        return '<p class="description" style="margin-top: 1em;">' . esc_html( $texto ) . '</p>';
    }

    /**
     * Estimativa grosseira do tempo que falta, em linguagem de gente.
     *
     * É um TETO, não uma promessa: o lote verifica até batch_size links de cada
     * vez, mas endereços repetidos só se pedem uma vez, por isso na prática
     * costuma acabar mais cedo. Prefere-se pecar por excesso — dizer "cerca de 2
     * horas" e acabar em 40 minutos é bom; o contrário é mentira.
     *
     * 🚨 A conta é a do `Cron::hours_for()`, a MESMA que a caixa da velocidade
     * mostra ao lado de cada nível. Até 22/08/2026 não era: aqui dividia-se pelo
     * lote e tratava-se o resultado como HORAS, o que só é verdade no nível
     * Cuidadoso — o único que existia quando isto foi escrito. Com o Normal, o
     * mesmo ecrã prometia "about 19 hours" na caixa e "about 4 days" duas linhas
     * abaixo. Quem lê acredita na pior das duas, e a razão de ser dos níveis
     * desfazia-se ali.
     */
    private function estimated_time_left( $pendentes ) {

        $cron  = new Cron( $this->store );
        $horas = Cron::hours_for( $pendentes, $cron->speed() );

        if ( $horas < 1 ) {
            return '';
        }

        if ( 1 === $horas ) {
            return __( 'Should be done within the hour.', 'onsite-link-verdict' );
        }

        if ( $horas < 24 ) {
            return sprintf(
                /* translators: %s: número de horas. */
                __( 'At most about %s hours left.', 'onsite-link-verdict' ),
                number_format_i18n( $horas )
            );
        }

        $dias = (int) ceil( $horas / 24 );

        return sprintf(
            /* translators: %s: número de dias. */
            _n( 'At most about %s day left.', 'At most about %s days left.', $dias, 'onsite-link-verdict' ),
            number_format_i18n( $dias )
        );
    }

    public function render() {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'onsite-link-verdict' ) );
        }

        $mensagem = $this->handle_actions();

        /*
         * O convite a avaliar. Fica ANTES do resto de propósito: quando o
         * utilizador carrega num dos botões, a resposta tem de ser tratada no
         * mesmo pedido, senão o convite volta a aparecer por baixo da própria
         * confirmação.
         */
        $convite          = new Review_Prompt( $this->store );
        $resposta_convite = $convite->handle_action();

        $contas = $this->store->count_by_status();
        $aba    = $this->current_tab();
        $cron   = new Cron( $this->store );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Onsite Link Verdict', 'onsite-link-verdict' ) . '</h1>';

        if ( $cron->is_real_cron_disabled() ) {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__( 'WordPress cron is disabled on this site (DISABLE_WP_CRON). Automatic checks will not run until you set up a real cron job with your host.', 'onsite-link-verdict' );
            echo '</p></div>';
        }

        if ( null !== $mensagem ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $mensagem ) . '</p></div>';
        }

        if ( false !== $resposta_convite ) {
            echo $convite->render_seguimento( $resposta_convite ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- montado e escapado no próprio método.
        } elseif ( $convite->deve_mostrar() ) {
            echo $convite->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- montado e escapado no próprio método.
        }

        echo '<form method="post" style="margin: 1em 0;">';
        wp_nonce_field( Cron::HOOK_QUEUE_ALL );
        echo '<input type="hidden" name="onsite_link_verdict_action" value="' . esc_attr( Cron::HOOK_QUEUE_ALL ) . '" />';
        echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Start scan', 'onsite-link-verdict' ) . '</button>';
        echo '</form>';

        $abas = array(
            Response_Classifier::BROKEN       => __( 'Broken', 'onsite-link-verdict' ),
            Response_Classifier::WARNING      => __( 'Warnings', 'onsite-link-verdict' ),
            Response_Classifier::UNVERIFIABLE => __( 'Unverifiable', 'onsite-link-verdict' ),
            Response_Classifier::OK           => __( 'Good', 'onsite-link-verdict' ),
        );

        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $abas as $chave => $rotulo ) {
            printf(
                '<a href="%s" class="nav-tab %s">%s <span class="count">(%d)</span></a>',
                esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=' . $chave ) ),
                $aba === $chave ? 'nav-tab-active' : '',
                esc_html( $rotulo ),
                (int) $contas[ $chave ]
            );
        }
        echo '</h2>';

        echo $this->pending_notice( (int) $contas['pending'], array_sum( $contas ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- montado e escapado no próprio método.

        $this->render_truncated_notice();

        /*
         * Os dois avisos sao INDEPENDENTES, e ha uma razao para isso estar dito
         * aqui: durante um dia este estava pendurado no fim do de cima, que sai
         * mais cedo quando nao ha nada cortado. Resultado: o aviso dos enderecos
         * grandes demais so aparecia acompanhado de outro problema, e nunca no
         * caso para que foi escrito. Nao voltar a encadear um aviso noutro.
         */
        $this->render_skipped_long_notice();

        /*
         * A LISTA PRIMEIRO. As definicoes e o convite vem depois dela.
         *
         * Ate 22/08/2026 estas duas caixas ficavam aqui em cima, entre os
         * separadores e a tabela, em TODAS as abas — e empurravam para fora do
         * primeiro ecra aquilo que a pessoa veio ver. Uma definicao que se toca
         * uma vez na vida nao pode estar a frente do trabalho, e o convite da
         * Pro ainda menos. Deu nas vistas ao tirar as capturas da loja, onde
         * metade da imagem era a definicao. Ha um teste a segurar esta ordem.
         */
        $tabela = new Links_List_Table( $this->store, $this );
        $tabela->prepare_items();
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
        echo '<input type="hidden" name="tab" value="' . esc_attr( $aba ) . '" />';
        $tabela->display();
        echo '</form>';

        $this->render_speed_box();
        $this->render_pro_box( $aba );

        echo '</div>';
    }

    /**
     * Avisa que houve conteúdo com mais links do que o teto.
     *
     * Existe porque o silêncio aqui era enganador: uma página com 1200 links
     * registava 1000 e o painel mostrava 1000 linhas como se fosse a lista
     * completa. O utilizador dava a página por verificada com 200 links por
     * olhar. Havia um `error_log()`, mas quem lê logs de servidor não é a mesma
     * pessoa que lê o painel.
     *
     * @return void
     */
    private function render_truncated_notice() {

        $cortadas = Scanner::truncated();

        if ( empty( $cortadas ) ) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>';
        printf(
            esc_html(
                /* translators: %d: teto de links por origem. */
                _n(
                    'One item has more links than we check in one pass (%d).',
                    'Some items have more links than we check in one pass (%d).',
                    count( $cortadas ),
                    'onsite-link-verdict'
                )
            ),
            (int) Scanner::MAX_LINKS_PER_SOURCE
        );
        echo '</strong> ';
        esc_html_e( 'The rest of the links in them have not been checked. Splitting the content into more than one page is the reliable fix.', 'onsite-link-verdict' );
        echo '</p><ul style="list-style:disc;margin-left:2em;">';

        foreach ( $cortadas as $chave => $total ) {
            echo '<li>' . $this->rotulo_da_origem( $chave ) . ' — ' . esc_html( sprintf( /* translators: %d: número de links. */ __( '%d links', 'onsite-link-verdict' ), (int) $total ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- o rótulo já vai escapado.
        }

        echo '</ul></div>';
    }

    /**
     * Avisa que houve endereços grandes demais para serem guardados.
     *
     * Um endereço com mais de 2048 caracteres é **recusado** e não truncado — de
     * propósito, porque um endereço cortado é outro endereço, e apareceria como
     * partido sendo bom. Mas recusar em silêncio deixa o utilizador com um link
     * que o plugin nunca verifica e sobre o qual nunca disse nada.
     *
     * @return void
     */
    private function render_skipped_long_notice() {

        $ignorados = Scanner::skipped_long();

        if ( empty( $ignorados ) ) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>';
        esc_html_e( 'Some links are too long to check.', 'onsite-link-verdict' );
        echo '</strong> ';
        esc_html_e( 'They are longer than 2048 characters — usually an address stuffed with tracking parameters. We do not shorten them, because a shortened address is a different address and would show up as broken when it is not.', 'onsite-link-verdict' );
        echo '</p><ul style="list-style:disc;margin-left:2em;">';

        foreach ( $ignorados as $chave => $quantos ) {
            echo '<li>' . $this->rotulo_da_origem( $chave ) . ' — ' . esc_html( sprintf( /* translators: %d: número de endereços. */ _n( '%d link', '%d links', (int) $quantos, 'onsite-link-verdict' ), (int) $quantos ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- o rótulo já vai escapado.
        }

        echo '</ul></div>';
    }

    /**
     * O nome de uma origem `tipo:id`, com ligação para a editar quando dá.
     *
     * @param string $chave Chave no formato `tipo:id`.
     * @return string HTML já escapado.
     */
    private function rotulo_da_origem( $chave ) {

        $partes = explode( ':', $chave );
        $tipo   = isset( $partes[0] ) ? $partes[0] : 'post';
        $id     = isset( $partes[1] ) ? (int) $partes[1] : 0;

        if ( 'post' !== $tipo ) {
            return esc_html( sprintf( '%s #%d', $tipo, $id ) );
        }

        $nome = get_the_title( $id );
        if ( '' === $nome ) {
            $nome = __( '(no title)', 'onsite-link-verdict' );
        }

        $editar = get_edit_post_link( $id );

        return $editar
            ? sprintf( '<a href="%s">%s</a>', esc_url( $editar ), esc_html( $nome ) )
            : esc_html( $nome );
    }

    /**
     * A caixa da versão Pro: exportar e regras de exclusão.
     *
     * Aparece a toda a gente, também a quem não comprou — e sem interromper
     * nada. É a mesma ideia da acção de linha: um convite no sítio onde a dor
     * está a acontecer vale mais do que um aviso que tapa o ecrã e impede o
     * acesso ao resto do painel enquanto não é fechado.
     *
     * @param string $aba Separador em que estamos, para o CSV exportar o certo.
     * @return void
     */
    private function render_pro_box( $aba ) {

        $pro = Pro::is_active();

        echo '<div class="card" style="max-width:none;margin-top:1em;">';
        echo '<h2 style="margin-top:0;">' . esc_html__( 'Onsite Link Verdict Pro', 'onsite-link-verdict' ) . '</h2>';

        if ( ! $pro ) {

            /*
             * 🔴 Há TRÊS estados aqui, e durante algum tempo só se distinguiam dois.
             *
             * Medido a 27/08/2026 num site de ensaio, com uma licença a sério: o
             * ecrã do Freemius dizia «W00t! Premium plugin version was
             * successfully activated» e, logo por baixo, esta caixa dizia «Pro
             * adds the mechanical work…» — ou seja, convidava a comprar quem
             * tinha acabado de comprar.
             *
             * A causa está do lado deles (o `can_use_premium_code()` responde que
             * não enquanto o opt-in por email não for confirmado) e não a podemos
             * mudar. A MENSAGEM podemos, e é o que se faz aqui: quem tem o pacote
             * pago instalado merece saber o que falta, não levar com um anúncio.
             *
             * No momento da compra, um produto a dizer uma coisa e a fazer outra
             * é a pior altura possível para o fazer.
             */
            if ( Pro::code_is_present() ) {
                echo '<p><strong>' . esc_html__( 'The Pro package is installed, but the licence is not active yet.', 'onsite-link-verdict' ) . '</strong></p>';
                echo '<p>' . esc_html__( 'If you have just entered your key, there is one step left: a confirmation email was sent to the address on this site, and Pro stays off until you click the button inside it. Look in your spam folder — that is where ours landed in testing.', 'onsite-link-verdict' ) . '</p>';
                echo '<p>' . esc_html__( 'Nothing was lost and nothing needs paying again. The features below turn on by themselves once the licence is confirmed.', 'onsite-link-verdict' ) . '</p>';
                echo '</div>';
                return;
            }

            echo '<p>' . esc_html__( 'Pro adds the mechanical work: replace a URL everywhere at once (with a preview and an undo), silence domains you never want to hear about again, and export the list to CSV.', 'onsite-link-verdict' ) . '</p>';
            echo '</div>';
            return;
        }

        // Exportar.
        $link_export = wp_nonce_url(
            add_query_arg(
                array(
                    'action' => self::ACTION_EXPORT,
                    'status' => $aba,
                ),
                admin_url( 'admin-post.php' )
            ),
            self::ACTION_EXPORT
        );

        echo '<p><a href="' . esc_url( $link_export ) . '" class="button">' . esc_html__( 'Export this tab to CSV', 'onsite-link-verdict' ) . '</a></p>';

        // Regras de exclusão.
        $regras = implode( "\n", Exclusions::rules() );

        echo '<form method="post">';
        wp_nonce_field( self::ACTION_EXCLUSIONS );
        echo '<input type="hidden" name="onsite_link_verdict_action" value="' . esc_attr( self::ACTION_EXCLUSIONS ) . '" />';

        echo '<p><label for="onsite-link-verdict-exclusions"><strong>' . esc_html__( 'Never check these', 'onsite-link-verdict' ) . '</strong></label><br />';
        echo '<span class="description">' . esc_html__( 'One pattern per line. Use * for anything. A line with no * means "contains". Lines starting with # are notes.', 'onsite-link-verdict' ) . '</span></p>';

        echo '<textarea id="onsite-link-verdict-exclusions" name="exclusions" rows="5" class="large-text code" placeholder="linkedin.com&#10;*amazon.*">' . esc_textarea( $regras ) . '</textarea>';

        echo '<p class="submit"><button type="submit" class="button button-secondary">' . esc_html__( 'Save rules', 'onsite-link-verdict' ) . '</button> ';
        echo '<span class="description">' . esc_html__( 'Rules apply as each post is checked again, not all at once. On a large site the list takes a few background runs to catch up.', 'onsite-link-verdict' ) . '</span></p>';
        echo '</form>';

        echo '</div>';
    }
}
