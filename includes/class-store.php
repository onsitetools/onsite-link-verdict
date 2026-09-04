<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Único ponto de contacto com a base de dados.
 *
 * Uma só tabela, com índices nas colunas por que se pesquisa. Guarda-se o
 * mínimo necessário para o veredito e o histórico de operações, e apaga-se
 * tudo ao desinstalar.
 */
class Store {

    /** Verificações falhadas seguidas antes de um aviso virar "partido". */
    const FAIL_STREAK_LIMIT = 3;

    /** Horas até um link verificado voltar à fila. */
    const RECHECK_AFTER_HOURS = 168; // uma semana

    /**
     * Horas até um link COM FALHAS EM CURSO (fail_streak > 0) voltar à fila.
     *
     * Janela mais curta do que a normal. Sem isto, um domínio genuinamente morto
     * demorava 3 semanas a chegar a "partido": cada uma das 3 falhas seguidas
     * exigidas pelo FAIL_STREAK_LIMIT só era reverificada ao fim de uma semana.
     * A proteção contra falsos positivos não muda — continuam a ser precisas 3
     * falhas seguidas — só a rapidez com que essas 3 falhas se sucedem.
     */
    const RECHECK_AFTER_HOURS_FAILING = 24;

    /** Opção onde fica gravada a versão do esquema já instalado. */
    const VERSION_OPTION = 'onsite_link_verdict_version';

    /**
     * Capacidade das colunas `url` e `final_url`, em caracteres.
     *
     * Tem de acompanhar o varchar(2048) do install(). Existe porque o MySQL em
     * modo estrito RECUSA a escrita de um valor maior do que a coluna — ao
     * contrário do SQLite, que aceita tudo. Sem estas guardas, o comportamento
     * do plugin dependia do motor da base de dados, e partia-se exactamente no
     * motor que os utilizadores têm.
     */
    const MAX_URL_LEN = 2048;

    /** Capacidade da coluna `error`, em caracteres. */
    const MAX_ERROR_LEN = 40;

    /**
     * Corta um valor à medida da coluna, contando CARACTERES e não bytes.
     *
     * O varchar(N) do MySQL conta caracteres; um `substr()` cego partiria um
     * caractere acentuado ao meio e gravaria lixo.
     */
    private static function caber( $valor, $max ) {
        $valor = (string) $valor;

        if ( function_exists( 'mb_strlen' ) ) {
            return mb_strlen( $valor, 'UTF-8' ) > $max
                ? mb_substr( $valor, 0, $max, 'UTF-8' )
                : $valor;
        }

        return strlen( $valor ) > $max ? substr( $valor, 0, $max ) : $valor;
    }

    /** Comprimento em caracteres, com ou sem mbstring. */
    /**
     * O endereço é maior do que a coluna aguenta?
     *
     * Público de propósito: a versão Pro precisa de recusar um endereço novo
     * grande demais ANTES de escrever no conteúdo de alguém. Ver a nota na
     * constante acima, e o teste do Replacer que veio de 20/08/2026.
     *
     * @param string $url Endereço a medir.
     * @return bool
     */
    public static function url_grande_demais( $url ) {
        return self::comprimento( $url ) > self::MAX_URL_LEN;
    }

    private static function comprimento( $valor ) {
        return function_exists( 'mb_strlen' )
            ? mb_strlen( (string) $valor, 'UTF-8' )
            : strlen( (string) $valor );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'onsite_link_verdict_links';
    }

    public function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tabela  = self::table_name();
        $collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$tabela} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url varchar(2048) NOT NULL,
            url_hash char(40) NOT NULL,
            anchor text NULL,
            type varchar(20) NOT NULL DEFAULT 'link',
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_type varchar(20) NOT NULL DEFAULT 'post',
            status varchar(20) NOT NULL DEFAULT 'pending',
            http_code smallint(6) NULL,
            error varchar(40) NOT NULL DEFAULT '',
            final_url varchar(2048) NOT NULL DEFAULT '',
            fail_streak smallint(6) NOT NULL DEFAULT 0,
            checked_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY origem_url (source_type,source_id,url_hash),
            KEY estado (status),
            KEY fila (checked_at)
        ) {$collate};";

        dbDelta( $sql );
    }

    /**
     * Caminho de atualização do esquema.
     *
     * O install() só corre no register_activation_hook, e ninguém reativa um plugin
     * para o atualizar — carrega-se em "Atualizar" e pronto. Sem isto, uma versão
     * futura com uma coluna nova nunca veria o dbDelta() correr na esmagadora
     * maioria das instalações, e o plugin partia-se em silêncio. Depois de haver
     * instalações no repositório, isto já não se consegue emendar.
     *
     * Chamado a cada carregamento do painel; a comparação é uma leitura de opção.
     *
     * @param string $version Versão do código a correr (ONSITE_LINK_VERDICT_VERSION).
     * @return bool Se o esquema foi (re)instalado nesta chamada.
     */
    public function maybe_upgrade( $version ) {

        if ( (string) get_option( self::VERSION_OPTION ) === (string) $version ) {
            return false;
        }

        $this->install();
        update_option( self::VERSION_OPTION, $version, false );

        return true;
    }

    public function drop() {
        global $wpdb;
        $tabela = self::table_name();
        $wpdb->query( "DROP TABLE IF EXISTS {$tabela}" ); // phpcs:ignore
    }

    /**
     * Regista um link. Se já existir na mesma origem, devolve o id existente.
     *
     * @return int
     */
    public function record( $link ) {
        global $wpdb;

        $tabela = self::table_name();

        /*
         * Um URL maior do que a coluna é IGNORADO de propósito, e nunca truncado.
         *
         * Truncar seria pior do que não gravar: o URL cortado é um endereço
         * diferente, que daria 404 na verificação e apareceria ao utilizador como
         * link partido quando o link do site está bom. Um falso positivo é o
         * defeito que este produto existe para não ter.
         *
         * Antes desta guarda o MySQL recusava a escrita e o link desaparecia sem
         * ninguém saber; o SQLite dos testes aceitava-a, e por isso 180 testes
         * verdes nunca viram o problema.
         */
        if ( self::comprimento( $link['url'] ) > self::MAX_URL_LEN ) {
            return 0;
        }

        $hash = sha1( $link['url'] );

        $existente = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT id FROM {$tabela} WHERE source_type = %s AND source_id = %d AND url_hash = %s", // phpcs:ignore
                $link['source_type'],
                $link['source_id'],
                $hash
            )
        );

        if ( $existente ) {
            return (int) $existente;
        }

        $escrito = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $tabela,
            array(
                'url'         => $link['url'],
                'url_hash'    => $hash,
                'anchor'      => isset( $link['anchor'] ) ? $link['anchor'] : '',
                'type'        => isset( $link['type'] ) ? $link['type'] : 'link',
                'source_id'   => (int) $link['source_id'],
                'source_type' => $link['source_type'],
                'status'      => 'pending',
                'created_at'  => current_time( 'mysql', true ),
            ),
            array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        /*
         * Sem este `false ===`, uma escrita falhada devolvia o insert_id da
         * ANTERIOR escrita bem-sucedida da mesma ligação — ou seja, o id de outra
         * linha, como se fosse desta.
         */
        if ( false === $escrito ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Apaga todas as linhas de uma origem.
     *
     * É o que corre quando um artigo é eliminado ou vai para o lixo, e quando um
     * comentário é eliminado ou deixa de estar aprovado. Sem isto, o painel
     * continuava a acusar links de conteúdo que já não existe — o utilizador ia ao
     * artigo, não o encontrava, e concluía que o plugin mente. Sem isto a tabela
     * cresceria para sempre: as entradas de conteúdo apagado nunca sairiam.
     *
     * @param string $source_type 'post' ou 'comment'.
     * @param int    $source_id   ID da origem.
     * @return int Linhas apagadas.
     */
    public function delete_by_source( $source_type, $source_id ) {
        global $wpdb;

        $tabela = self::table_name();

        return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "DELETE FROM {$tabela} WHERE source_type = %s AND source_id = %d", // phpcs:ignore
                $source_type,
                (int) $source_id
            )
        );
    }

    /** Marcadores por bloco de NOT IN, no delete_missing_from_source(). */
    const DELETE_HASH_BLOCK = 500;

    /**
     * Apaga as linhas de uma origem cujos URLs já não constam da lista dada.
     *
     * O caso banal: apaga-se um link de um artigo e volta a gravar-se. O Scanner
     * regista os links que encontrou desta vez; os que estavam gravados e não
     * vieram já lá não estão, e têm de sair da lista.
     *
     * Lista vazia significa que a origem ficou sem links nenhuns — sai tudo.
     *
     * O `NOT IN` vem partido em blocos de DELETE_HASH_BLOCK marcadores, ANDados
     * entre si numa ÚNICA instrução: uma origem com milhares de links dava uma
     * instrução preparada de centenas de KB, tudo dentro de um pedido de cron.
     * Repara-se que isto tem de ser um AND de vários "NOT IN" e NUNCA várias
     * instruções DELETE em série — uma linha só pode sair se o hash dela não
     * estiver em NENHUM bloco, e só o AND garante isso; um DELETE por bloco
     * apagaria linhas que estão presentes noutro bloco.
     *
     * @param string   $source_type 'post' ou 'comment'.
     * @param int      $source_id   ID da origem.
     * @param string[] $urls        URLs encontrados nesta passagem.
     * @return int Linhas apagadas.
     */
    public function delete_missing_from_source( $source_type, $source_id, $urls ) {
        global $wpdb;

        if ( empty( $urls ) ) {
            return $this->delete_by_source( $source_type, $source_id );
        }

        $tabela  = self::table_name();
        $hashes  = array_values( array_unique( array_map( 'sha1', $urls ) ) );
        $blocos  = array_chunk( $hashes, self::DELETE_HASH_BLOCK );

        $condicoes = array();
        $valores   = array( $source_type, (int) $source_id );

        foreach ( $blocos as $bloco ) {
            $marcas      = implode( ',', array_fill( 0, count( $bloco ), '%s' ) );
            $condicoes[] = "url_hash NOT IN ({$marcas})";
            $valores     = array_merge( $valores, $bloco );
        }

        $where = implode( ' AND ', $condicoes );

        /*
         * A ferramenta de revisão diz aqui "1 argumento, esperava 2". É falso
         * alarme: o `$valores` já começa com o source_type e o source_id (ver
         * acima) e só depois leva os hashes, e o `$wpdb->prepare()` aceita um
         * array único com todos os valores. O sniffer conta os argumentos
         * escritos à mão e não consegue olhar para dentro do array.
         *
         * Verificado a 19/08/2026, e o teste do delete_missing_from_source()
         * cobre o comportamento.
         */
        return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                "DELETE FROM {$tabela} WHERE source_type = %s AND source_id = %d AND ( {$where} )", // phpcs:ignore
                $valores
            )
        );
    }

    /**
     * Grava o que o Checker devolveu, aplicando a regra da série de falhas.
     */
    public function update_result( $id, $resultado ) {
        global $wpdb;

        $tabela = self::table_name();

        $streak_atual = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( "SELECT fail_streak FROM {$tabela} WHERE id = %d", $id ) // phpcs:ignore
        );

        $status = $resultado['status'];

        if ( Response_Classifier::WARNING === $status ) {
            $streak = $streak_atual + 1;

            /*
             * Ao fim da série, o veredito depende de UMA pergunta: houve alguém
             * do outro lado a responder?
             *
             * Se o servidor de destino respondeu alguma coisa — 500, 503, 400,
             * um 3xx em ciclo —, ele existe, está de pé, e recusou-se a
             * servir-nos. Isso não prova que a página desapareceu, prova que não
             * conseguimos vê-la. Vai para "não consegui verificar".
             *
             * Isto substitui a regra antiga, que promovia qualquer aviso repetido
             * a PARTIDO, e que era um defeito com nome: a Amazon responde 503 a
             * robôs de forma persistente, portanto a terceira passagem marcava os
             * links da Amazon como partidos — um falso positivo garantido em
             * qualquer site que ligue para lá. Estávamos a construir o mesmo
             * defeito.
             *
             * Se NÃO houve resposta nenhuma (http_code a null: DNS que não
             * resolve, ligação recusada, certificado inválido, tempo esgotado),
             * então não há servidor. É o perfil do domínio que expirou, que é a
             * causa número um de link rot, e continua a ser motivo para acusar.
             *
             * A série NÃO é reposta ao passar a "não verificável". Chegou a estar,
             * e era um erro que só um teste com quatro passagens apanhou: com a
             * série a zero, a verificação seguinte voltava a dar aviso, e o link
             * andava a saltar entre "aviso" e "não verificável" de três em três
             * passagens, para sempre. O que trata de não martelar o site alheio é
             * o get_due_for_check(), que só aplica a janela curta a linhas ainda
             * por decidir.
             */
            if ( $streak >= self::FAIL_STREAK_LIMIT ) {
                $houve_resposta = isset( $resultado['http_code'] ) && null !== $resultado['http_code'] && (int) $resultado['http_code'] > 0;

                $status = $houve_resposta ? Response_Classifier::UNVERIFIABLE : Response_Classifier::BROKEN;
            }
        } elseif ( Response_Classifier::OK === $status ) {
            $streak = 0;
        } else {
            $streak = $streak_atual;
        }

        /*
         * O `final_url` e o `error` são CORTADOS à medida da coluna, e aqui
         * truncar é seguro: os dois são informativos, não se voltam a pedir.
         *
         * É a guarda mais importante do ficheiro. Um servidor pode redirecionar
         * para um endereço enorme (acontece com parâmetros de rastreio que se
         * acumulam em cadeia). Em MySQL estrito, esse valor fazia falhar o UPDATE
         * INTEIRO — e como o `status` e o `checked_at` iam no mesmo UPDATE, a
         * linha ficava eternamente em `pending`, voltava à fila em todos os lotes
         * e o plugin repetia o pedido àquele endereço para sempre. Ou seja: o
         * plugin passava a martelar o site alheio de hora a hora, sem nunca
         * conseguir gravar o resultado e sair da fila.
         */
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $tabela,
            array(
                'status'      => $status,
                'http_code'   => isset( $resultado['http_code'] ) ? $resultado['http_code'] : null,
                'error'       => self::caber( isset( $resultado['error'] ) ? $resultado['error'] : '', self::MAX_ERROR_LEN ),
                'final_url'   => self::caber( isset( $resultado['final_url'] ) ? $resultado['final_url'] : '', self::MAX_URL_LEN ),
                'fail_streak' => $streak,
                'checked_at'  => gmdate( 'Y-m-d H:i:s', isset( $resultado['checked_at'] ) ? $resultado['checked_at'] : time() ),
            ),
            array( 'id' => (int) $id ),
            array( '%s', '%d', '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Todas as linhas com este endereço, seja qual for a origem.
     *
     * É o que a substituição em massa da versão Pro percorre: a tabela já sabe
     * em que artigo ou comentário cada endereço está, e é isso que torna a
     * substituição cirúrgica em vez de um "procurar e substituir" na base de
     * dados — que é o que estraga conteúdo serializado.
     *
     * @param string $url    Endereço exacto.
     * @param int    $limite Máximo de linhas.
     * @return array
     */
    public function get_by_url( $url, $limite = 500 ) {
        global $wpdb;
        $tabela = self::table_name();

        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT * FROM {$tabela} WHERE url_hash = %s ORDER BY id ASC LIMIT %d", // phpcs:ignore
                sha1( (string) $url ),
                (int) $limite
            ),
            ARRAY_A
        );
    }

    /**
     * Quantas linhas há com este endereço.
     *
     * Existe para a substituição poder dizer quanto falta em vez de truncar em
     * silêncio.
     *
     * @param string $url Endereço exacto.
     * @return int
     */
    public function count_by_url( $url ) {
        global $wpdb;
        $tabela = self::table_name();

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( "SELECT COUNT(*) FROM {$tabela} WHERE url_hash = %s", sha1( (string) $url ) ) // phpcs:ignore
        );
    }

    /**
     * Troca o endereço de uma linha, depois de o conteúdo ter sido alterado.
     *
     * Faz-se apagando e voltando a registar, em vez de um UPDATE ao `url`, por
     * duas razões: o `url_hash` faz parte da chave única (`source_type`,
     * `source_id`, `url_hash`) e teria de ser recalculado à mão; e o endereço
     * novo tem de entrar como **por verificar**, que é exactamente o que o
     * record() faz. Um endereço novo que herdasse o estado do antigo apareceria
     * como partido sem nunca ter sido testado.
     *
     * @param string $source_type 'post' ou 'comment'.
     * @param int    $source_id   Id da origem.
     * @param string $antigo      Endereço a sair.
     * @param string $novo        Endereço a entrar.
     * @return void
     */
    public function rename_url( $source_type, $source_id, $antigo, $novo ) {
        global $wpdb;

        $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            self::table_name(),
            array(
                'source_type' => (string) $source_type,
                'source_id'   => (int) $source_id,
                'url_hash'    => sha1( (string) $antigo ),
            ),
            array( '%s', '%d', '%s' )
        );

        $this->record(
            array(
                'url'         => (string) $novo,
                'anchor'      => '',
                'type'        => 'link',
                'source_id'   => (int) $source_id,
                'source_type' => (string) $source_type,
            )
        );
    }

    /**
     * Uma página de resultados de um estado.
     *
     * O desempate por `id` não é enfeite: o run_batch() grava 20 linhas com o
     * MESMO segundo no checked_at, por isso os empates são a norma, não a exceção.
     * Sem critério de desempate, a ordem não é estável entre a consulta da página
     * 1 e a da página 2 — o utilizador via a mesma linha duas vezes e outra nunca.
     */
    public function get_by_status( $status, $limit = 50, $offset = 0 ) {
        global $wpdb;
        $tabela = self::table_name();

        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT * FROM {$tabela} WHERE status = %s ORDER BY checked_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore
                $status,
                $limit,
                $offset
            ),
            ARRAY_A
        );
    }

    /**
     * Links que nunca foram verificados, ou cuja verificação já envelheceu.
     *
     * Uma linha com falhas em curso (fail_streak > 0) usa a janela mais curta
     * RECHECK_AFTER_HOURS_FAILING em vez da normal — é o que faz um domínio morto
     * chegar a "partido" em dias, não em semanas (ver o comentário da constante).
     * Uma linha sem falhas (fail_streak = 0) continua só na janela normal.
     *
     * Desempate por `id` pela mesma razão do get_by_status(): sem ele, os empates
     * de checked_at (que aqui são ainda mais frequentes — todos os links nunca
     * verificados têm checked_at a NULL) davam uma ordem instável, e o lote podia
     * repetir uns links e nunca chegar a outros. Não mexer nisto.
     */
    public function get_due_for_check( $limit ) {
        global $wpdb;
        $tabela        = self::table_name();
        $limite        = gmdate( 'Y-m-d H:i:s', time() - ( self::RECHECK_AFTER_HOURS * HOUR_IN_SECONDS ) );
        $limite_falhas = gmdate( 'Y-m-d H:i:s', time() - ( self::RECHECK_AFTER_HOURS_FAILING * HOUR_IN_SECONDS ) );

        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                /*
                 * A janela curta só se aplica a linhas AINDA POR DECIDIR
                 * (status = 'warning'). Existe para acelerar um veredito, não
                 * para repetir um veredito já dado.
                 *
                 * Sem a condição do estado, um link em "não consegui verificar"
                 * — tipicamente um site que nos bloqueia — voltava à fila de 24
                 * em 24 horas, para sempre, e o plugin passava a bater à porta
                 * de quem já nos disse que não fala com robôs. É carga
                 * desnecessária num servidor alheio, e não a podemos ter cá
                 * dentro.
                 */
                "SELECT * FROM {$tabela} WHERE checked_at IS NULL OR checked_at < %s OR ( fail_streak > 0 AND status = 'warning' AND checked_at < %s ) ORDER BY checked_at ASC, id ASC LIMIT %d", // phpcs:ignore
                $limite,
                $limite_falhas,
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Põe o link de volta à frente da fila de verificação, ignorando o
     * agendamento normal (o get_due_for_check() ordena por checked_at ASC e
     * apanha primeiro os NULL). É o que a ação "Verificar de novo" do painel usa.
     */
    public function mark_for_recheck( $id ) {
        global $wpdb;
        $tabela = self::table_name();

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $tabela,
            array( 'checked_at' => null ),
            array( 'id' => (int) $id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    public function count_by_status() {
        global $wpdb;
        $tabela = self::table_name();

        $linhas = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$tabela} GROUP BY status", ARRAY_A ); // phpcs:ignore

        $contas = array(
            Response_Classifier::OK           => 0,
            Response_Classifier::BROKEN       => 0,
            Response_Classifier::WARNING      => 0,
            Response_Classifier::UNVERIFIABLE => 0,
            'pending'                         => 0,
        );

        foreach ( (array) $linhas as $linha ) {
            $contas[ $linha['status'] ] = (int) $linha['total'];
        }

        return $contas;
    }
}
