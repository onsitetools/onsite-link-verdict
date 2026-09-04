<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Percorre o conteúdo do site e alimenta o Store com os links encontrados.
 * Não faz pedidos HTTP nenhuns — só lê e regista. Quem verifica é o Checker.
 */
class Scanner {

    /**
     * Itens (artigos ou comentários) varridos por passagem.
     *
     * Cada artigo custa um get_post(), um apply_filters('the_content') e um
     * get_permalink(); cada link custa um SELECT e um INSERT. Cinquenta itens é o
     * que cabe com folga num pedido de cron de alojamento partilhado.
     */
    const QUEUE_BATCH = 50;

    /** Opção onde fica guardado o ponto em que o varrimento completo ficou. */
    const CURSOR_OPTION = 'onsite_link_verdict_queue_cursor';

    /** Opção com as origens cujo conteúdo passou do teto de links. */
    const TRUNCATED_OPTION = 'onsite_link_verdict_truncated';

    /** Opção com as origens que tinham endereços grandes demais para a coluna. */
    const SKIPPED_LONG_OPTION = 'onsite_link_verdict_skipped_long';

    /** Quantas origens cortadas se guardam. É uma opção, não um depósito. */
    const MAX_TRUNCATIONS = 20;

    /**
     * Teto de links registados por origem, numa só passagem.
     *
     * Generoso de propósito — uma página normal tem dezenas de links, não
     * milhares — para nunca tocar no caso comum. Protege o caso raro de uma
     * página de recursos com milhares de links: sem teto, um único artigo custa
     * 2×N consultas (SELECT+INSERT por link) e uma instrução de limpeza gigante,
     * tudo dentro de um pedido de cron, sem o relógio ser consultado nenhuma vez
     * (o orçamento de tempo do queue_all() só é verificado ENTRE itens).
     *
     * Quando uma origem excede o teto, a limpeza (delete_missing_from_source)
     * fica desligada PARA ESSA ORIGEM nesta passagem — ver o registar(). Não se
     * conhece o conjunto completo de links desta vez, por isso não se pode
     * concluir que os que não vieram foram removidos do conteúdo; concluir isso
     * apagaria linhas de links que continuam lá, só que a partir do teto.
     */
    const MAX_LINKS_PER_SOURCE = 1000;

    /** @var Store */
    private $store;

    /** @var Link_Extractor */
    private $extractor;

    /** @var Cron */
    private $cron;

    /** @var Content_Renderer */
    private $renderer;

    public function __construct( $store = null, $extractor = null, $cron = null, $renderer = null ) {
        $this->store     = $store ? $store : new Store();
        $this->extractor = $extractor ? $extractor : new Link_Extractor();
        $this->cron      = $cron ? $cron : new Cron( $this->store );
        $this->renderer  = $renderer ? $renderer : new Content_Renderer();
    }

    /**
     * Varre um artigo publicado e regista os links encontrados.
     *
     * @param int $post_id ID do artigo.
     * @return int Número de links registados.
     */
    public function scan_post( $post_id ) {

        $post = get_post( $post_id );

        if ( ! $post || 'publish' !== $post->post_status ) {
            return 0;
        }

        $html = $this->renderer->render( $post );
        $base = get_permalink( $post );

        return $this->registar( $this->extractor->extract( $html, $base ), (int) $post->ID, 'post' );
    }

    /**
     * Varre um comentário aprovado e regista os links encontrados.
     *
     * @param int $comment_id ID do comentário.
     * @return int Número de links registados.
     */
    public function scan_comment( $comment_id ) {

        $comment = get_comment( $comment_id );

        if ( ! $comment || '1' !== (string) $comment->comment_approved ) {
            return 0;
        }

        $base = get_permalink( $comment->comment_post_ID );

        return $this->registar(
            $this->extractor->extract( $comment->comment_content, $base ),
            (int) $comment->comment_ID,
            'comment'
        );
    }

    /**
     * As origens cujo conteúdo tem mais links do que o teto.
     *
     * @return array Chave `tipo:id` → número de links encontrados.
     */
    public static function truncated() {
        return self::sem_orfas( self::TRUNCATED_OPTION, self::notas( self::TRUNCATED_OPTION ) );
    }

    /**
     * As origens onde houve endereços grandes demais para a coluna.
     *
     * @return array Chave `tipo:id` → quantos foram postos de lado.
     */
    public static function skipped_long() {
        return self::sem_orfas( self::SKIPPED_LONG_OPTION, self::notas( self::SKIPPED_LONG_OPTION ) );
    }

    /**
     * @param string $opcao Opção a ler.
     * @return array
     */
    private static function notas( $opcao ) {
        $guardado = get_option( $opcao, array() );

        return is_array( $guardado ) ? $guardado : array();
    }

    /**
     * Deita fora as notas cujo conteúdo já não existe — e limpa-as da opção.
     *
     * Apagar o artigo é uma das maneiras legítimas de resolver o problema de que
     * a nota falava. Sem isto, o aviso ficava no painel para sempre, sem título
     * (o artigo já não existe) e sem forma de o desligar. Um aviso permanente
     * deixa de ser lido, que é o contrário do que estes avisos existem para
     * fazer. Visto a correr o plugin a sério em MySQL, 20/08/2026.
     *
     * Limpa-se na LEITURA para o painel, e não ao gravar: durante o varrimento a
     * origem acabou de ser lida e existe de certeza, e pagar um `get_post()` por
     * nota em cada gravação era trabalho para nada.
     *
     * @param string $opcao Opção onde as notas vivem.
     * @param array  $lista Notas guardadas.
     * @return array As que ainda fazem sentido.
     */
    private static function sem_orfas( $opcao, $lista ) {

        $vivas = array();

        foreach ( $lista as $chave => $valor ) {

            $partes = explode( ':', (string) $chave );
            $tipo   = isset( $partes[0] ) ? $partes[0] : '';
            $id     = isset( $partes[1] ) ? (int) $partes[1] : 0;

            $existe = 'comment' === $tipo
                ? (bool) get_comment( $id )
                : (bool) get_post( $id );

            if ( $existe ) {
                $vivas[ $chave ] = $valor;
            }
        }

        if ( count( $vivas ) !== count( $lista ) ) {
            update_option( $opcao, $vivas, false );
        }

        return $vivas;
    }

    /**
     * Guarda uma nota sobre uma origem, sem deixar a lista crescer sem fim.
     *
     * @param string $opcao       Opção.
     * @param string $source_type 'post' ou 'comment'.
     * @param int    $source_id   Id.
     * @param int    $valor       Número a guardar.
     * @return void
     */
    private static function nota( $opcao, $source_type, $source_id, $valor ) {

        $lista = self::notas( $opcao );
        $chave = $source_type . ':' . (int) $source_id;

        if ( isset( $lista[ $chave ] ) && (int) $lista[ $chave ] === (int) $valor ) {
            return; // Nada mudou; não se escreve na base de dados por nada.
        }

        $lista[ $chave ] = (int) $valor;

        // As mais antigas saem. Um aviso com vinte linhas já ninguém lê; com
        // duzentas, é uma opção a engordar em cada carregamento do painel.
        if ( count( $lista ) > self::MAX_TRUNCATIONS ) {
            $lista = array_slice( $lista, -self::MAX_TRUNCATIONS, null, true );
        }

        update_option( $opcao, $lista, false );
    }

    /**
     * @param string $opcao       Opção.
     * @param string $source_type 'post' ou 'comment'.
     * @param int    $source_id   Id.
     * @return void
     */
    private static function apagar_nota( $opcao, $source_type, $source_id ) {

        $lista = self::notas( $opcao );
        $chave = $source_type . ':' . (int) $source_id;

        if ( ! isset( $lista[ $chave ] ) ) {
            return;
        }

        unset( $lista[ $chave ] );
        update_option( $opcao, $lista, false );
    }

    /**
     * @param string $source_type 'post' ou 'comment'.
     * @param int    $source_id   Id da origem.
     * @param int    $total       Links encontrados ao todo.
     * @return void
     */
    public static function record_truncation( $source_type, $source_id, $total ) {
        self::nota( self::TRUNCATED_OPTION, $source_type, $source_id, $total );
    }

    /**
     * @param string $source_type 'post' ou 'comment'.
     * @param int    $source_id   Id da origem.
     * @return void
     */
    public static function clear_truncation( $source_type, $source_id ) {
        self::apagar_nota( self::TRUNCATED_OPTION, $source_type, $source_id );
    }

    /**
     * @return void
     */
    public static function forget_truncations() {
        delete_option( self::TRUNCATED_OPTION );
        delete_option( self::SKIPPED_LONG_OPTION );
    }

    /**
     * Coloca o site em fila para verificação — artigos E comentários aprovados —,
     * UMA FATIA DE CADA VEZ.
     *
     * Esta é a operação mais pesada do plugin, e durante muito tempo foi a única
     * sem proteção nenhuma: trazia todos os ids de artigos publicados e todos os
     * de comentários aprovados e processava-os em série no mesmo pedido. Num site
     * de 5.000 artigos com 15 links cada isso são ~150.000 consultas, 5.000
     * execuções de the_content e a cache de 5.000 objetos, tudo de uma vez. Morria
     * por tempo ou por memória — e, pior, o WordPress desagenda o evento ANTES de
     * o correr, por isso o trabalho perdia-se sem deixar rasto e sem retoma.
     *
     * O caminho leve (gravar um artigo) já tinha sido tornado assíncrono por causa
     * dos pedidos HTTP de oEmbed que o the_content pode disparar; a mesma correção
     * nunca tinha chegado ao caminho pesado.
     *
     * Agora: no máximo QUEUE_BATCH itens por passagem, com o MESMO orçamento de
     * tempo do run_batch() (Cron::time_budget(), para não haver dois números a
     * dizer a mesma coisa), o ponto onde ficou guardado numa opção, e o evento a
     * reagendar-se a si próprio enquanto houver trabalho. Quando acaba, o cursor é
     * apagado e não se reagenda nada.
     *
     * @param array $post_types Tipos de artigos a varrer. Se vazio, todos os tipos
     *                          públicos. Só é lido quando um varrimento começa —
     *                          uma retoma usa os tipos que ficaram no cursor.
     * @return int Número de links registados NESTA passagem.
     */
    public function queue_all( $post_types = array() ) {

        // Mesma razão da guarda no Cron::run_batch(): o varrimento também pode
        // correr sem que ninguém tenha aberto o painel deste site.
        $this->store->maybe_upgrade( defined( 'ONSITE_LINK_VERDICT_VERSION' ) ? ONSITE_LINK_VERDICT_VERSION : 'dev' );

        // Mesma corrida do Cron::run_batch(): este evento reagenda-se a si
        // próprio, portanto duas passagens podem sobrepor-se e repetir trabalho.
        if ( ! $this->cron->lock_scan() ) {
            return 0;
        }

        try {
            return $this->correr_varrimento( $post_types );
        } finally {
            $this->cron->unlock_scan();
        }
    }

    /**
     * O trabalho do varrimento, já com a porta fechada.
     *
     * @param array $post_types Tipos de artigos a varrer.
     * @return int
     */
    private function correr_varrimento( $post_types ) {

        $cursor    = $this->ler_cursor( $post_types );
        $inicio    = microtime( true );
        $orcamento = $this->cron->time_budget();
        $total     = 0;
        $feitos    = 0;

        while ( $feitos < self::QUEUE_BATCH && ( microtime( true ) - $inicio ) < $orcamento ) {

            $ids = $this->ids_da_etapa( $cursor, self::QUEUE_BATCH - $feitos );

            if ( empty( $ids ) ) {
                // Etapa esgotada: passa-se aos comentários ou acabou o trabalho todo.
                if ( 'posts' === $cursor['stage'] ) {
                    $cursor['stage']  = 'comments';
                    $cursor['offset'] = 0;
                    continue;
                }

                delete_option( self::CURSOR_OPTION );
                return $total;
            }

            foreach ( $ids as $id ) {

                // Mesma disciplina do run_batch(): o relógio é consultado antes de
                // cada item, e o que ficar por fazer fica no cursor para a passagem
                // seguinte. Nada se perde.
                if ( ( microtime( true ) - $inicio ) >= $orcamento ) {
                    break;
                }

                $total += 'posts' === $cursor['stage']
                    ? $this->scan_post( $id )
                    : $this->scan_comment( $id );

                $cursor['offset']++;
                $feitos++;
            }
        }

        update_option( self::CURSOR_OPTION, $cursor, false );
        $this->cron->schedule_queue_all_continuation();

        return $total;
    }

    /**
     * Lê o cursor guardado, ou abre um novo se não houver varrimento a meio.
     *
     * @param array $post_types Tipos pedidos por quem arrancou o varrimento.
     * @return array
     */
    private function ler_cursor( $post_types ) {

        $cursor = get_option( self::CURSOR_OPTION );

        if ( is_array( $cursor ) && isset( $cursor['stage'], $cursor['offset'], $cursor['post_types'] ) ) {
            return $cursor;
        }

        if ( empty( $post_types ) ) {
            $post_types = get_post_types( array( 'public' => true ) );
        }

        return array(
            'stage'      => 'posts',
            'offset'     => 0,
            'post_types' => array_values( (array) $post_types ),
        );
    }

    /**
     * Os próximos ids da etapa em que o cursor está.
     *
     * Só ids ('fields' => 'ids'), nunca objetos: um site com dezenas de milhares
     * de comentários não pode carregá-los todos para memória — e é precisamente o
     * conteúdo antigo e acumulado que costuma ter os links mortos.
     *
     * A ordem é sempre por id ascendente, para o offset significar sempre a mesma
     * coisa entre passagens.
     *
     * @param array $cursor  Cursor atual.
     * @param int   $limite  Quantos ids no máximo.
     * @return int[]
     */
    private function ids_da_etapa( $cursor, $limite ) {

        if ( 'posts' === $cursor['stage'] ) {
            return get_posts( array(
                'post_type'      => $cursor['post_types'],
                'post_status'    => 'publish',
                'posts_per_page' => $limite,
                'offset'         => $cursor['offset'],
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ) );
        }

        return get_comments( array(
            'status'  => 'approve',
            'fields'  => 'ids',
            'number'  => $limite,
            'offset'  => $cursor['offset'],
            'orderby' => 'comment_ID',
            'order'   => 'ASC',
        ) );
    }

    /**
     * Regista uma lista de links no Store.
     *
     * @param array $links Lista de links devolvidos pelo Link_Extractor.
     * @param int   $source_id ID da origem (id do artigo ou comentário).
     * @param string $source_type Tipo de origem ('post' ou 'comment').
     * @return int Número de links registados.
     */
    private function registar( $links, $source_id, $source_type ) {

        $excedeu_teto = count( $links ) > self::MAX_LINKS_PER_SOURCE;

        /*
         * O corte tem de ser DITO, não só registado no log.
         *
         * Uma página de recursos com 1200 links registava 1000 e o painel não
         * dizia nada: o utilizador via 1000 linhas, concluía que a página estava
         * verificada, e ficava com 200 links por olhar sem nunca saber. Um
         * `error_log()` não é dizer ao utilizador — é dizer a quem lê logs de
         * servidor, que não é a mesma pessoa.
         */
        if ( $excedeu_teto ) {
            self::record_truncation( $source_type, $source_id, count( $links ) );
            $links = array_slice( $links, 0, self::MAX_LINKS_PER_SOURCE );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- aviso discreto de operação, não de erro fatal; não há UI para isto de propósito.
            error_log( sprintf(
                'Onsite Link Verdict: a origem %s#%d tem mais de %d links; registados so os primeiros %d nesta passagem, limpeza desligada.',
                $source_type,
                $source_id,
                self::MAX_LINKS_PER_SOURCE,
                self::MAX_LINKS_PER_SOURCE
            ) );
        }

        $total     = 0;
        $ignorados = 0;
        $urls      = array();
        /*
         * As regras de exclusão são da versão paga, e no pacote gratuito a
         * classe delas nem vai — a regra 5 do repositório não deixa. Perguntar
         * ao portão primeiro é o que impede um "class not found" no meio de um
         * varrimento, que é a pior altura para o site ir abaixo.
         */
        $regras = Pro::is_active() ? Exclusions::rules() : array();

        foreach ( $links as $link ) {

            /*
             * Endereços que o utilizador mandou esquecer (versão Pro).
             *
             * Ficam de fora do $urls de propósito, e não só do record(): assim o
             * delete_missing_from_source() logo abaixo trata de tirar da lista os
             * que já lá estavam. Acrescentar uma regra e voltar a varrer limpa o
             * que a regra apanha, sem ser preciso mais nada.
             */
            if ( ! empty( $regras ) && Exclusions::matches( $link['url'], $regras ) ) {
                continue;
            }

            $link['source_id']   = $source_id;
            $link['source_type'] = $source_type;

            /*
             * O record() devolve 0 quando NÃO guardou — hoje só acontece com um
             * endereço maior do que a coluna (2048 caracteres), que é recusado de
             * propósito: cortá-lo daria um endereço DIFERENTE, e esse apareceria
             * como partido sendo bom.
             *
             * O que estava errado era contá-lo na mesma. O scan_post() devolvia
             * 2 quando só tinha guardado 1, e esse número alimenta o que o painel
             * mostra. Contar o que não ficou guardado é mentir em pequeno, todos
             * os dias — e é a mesma família do resto do que se corrigiu hoje.
             */
            if ( ! $this->store->record( $link ) ) {
                $ignorados++;
                continue;
            }

            $urls[] = $link['url'];
            $total++;
        }

        if ( $ignorados > 0 ) {
            self::nota( self::SKIPPED_LONG_OPTION, $source_type, $source_id, $ignorados );
        } else {
            self::apagar_nota( self::SKIPPED_LONG_OPTION, $source_type, $source_id );
        }

        // O varrimento é a fotografia do estado atual desta origem: o que estava
        // gravado e não apareceu agora foi apagado do conteúdo, e tem de sair da
        // lista. Sem isto, apagar um link de um artigo deixava-o na aba "Broken"
        // para sempre — o utilizador ia lá, não o encontrava, e concluía que o
        // plugin mente.
        //
        // Mas só quando se conhece o conjunto COMPLETO: se o teto cortou a lista,
        // "o que não veio" não quer dizer "foi removido do conteúdo" — quer dizer
        // "ficou de fora do corte". Concluir isso apagava links que continuam lá.
        if ( ! $excedeu_teto ) {
            self::clear_truncation( $source_type, $source_id );
            $this->store->delete_missing_from_source( $source_type, $source_id, $urls );
        }

        return $total;
    }
}
