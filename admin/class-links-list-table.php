<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * A tabela de resultados. Usa a WP_List_Table para herdar de graça a paginação
 * e o aspeto nativo do WordPress — um plugin que parece do WordPress ganha confiança.
 */
class Links_List_Table extends \WP_List_Table {

    const PER_PAGE = 25;

    /** Caracteres do texto do link mostrados na tabela, antes de cortar. */
    const MAX_ANCHOR_DISPLAY = 80;

    /** @var Store */
    private $store;

    /** @var Admin_Page */
    private $pagina;

    public function __construct( $store, $pagina ) {
        parent::__construct( array(
            'singular' => 'link',
            'plural'   => 'links',
            'ajax'     => false,
        ) );

        $this->store  = $store;
        $this->pagina = $pagina;
    }

    public function get_columns() {
        return array(
            'anchor'     => __( 'Link text', 'onsite-link-verdict' ),
            'url'        => __( 'URL', 'onsite-link-verdict' ),
            'explicacao' => __( "What's happening", 'onsite-link-verdict' ),
            'origem'     => __( 'Where it is', 'onsite-link-verdict' ),
            'checked_at' => __( 'Checked', 'onsite-link-verdict' ),
        );
    }

    public function prepare_items() {

        $aba     = $this->pagina->current_tab();
        $pagina  = max( 1, (int) $this->get_pagenum() );
        $offset  = ( $pagina - 1 ) * self::PER_PAGE;
        $contas  = $this->store->count_by_status();

        $this->items = $this->store->get_by_status( $aba, self::PER_PAGE, $offset );

        $this->set_pagination_args( array(
            'total_items' => (int) $contas[ $aba ],
            'per_page'    => self::PER_PAGE,
        ) );

        $this->_column_headers = array( $this->get_columns(), array(), array() );
    }

    /**
     * Coluna do texto do link — é também onde vive a row action "Verificar de
     * novo" (o padrão do WordPress é pendurá-las na coluna principal, visível
     * ao passar o rato por cima da linha).
     */
    public function column_anchor( $item ) {

        $texto = '' !== trim( (string) $item['anchor'] ) ? $item['anchor'] : __( '(no text)', 'onsite-link-verdict' );

        /*
         * O texto do link é do site alheio, e pode ser enorme — um link que
         * envolve um parágrafo inteiro traz o parágrafo inteiro para aqui. Numa
         * instalação real isso empurrava as outras colunas para fora do ecrã e
         * dava uma linha de tabela com centenas de palavras.
         *
         * Corta-se só para MOSTRAR: o valor guardado não muda, e o título passa
         * a ter o texto completo para quem precisar de o ler.
         */
        $completo = $texto;
        $texto    = wp_html_excerpt( $texto, self::MAX_ANCHOR_DISPLAY, '…' );

        $link_recheck = wp_nonce_url(
            add_query_arg(
                array(
                    'page'               => Admin_Page::MENU_SLUG,
                    'tab'                => $this->pagina->current_tab(),
                    'onsite_link_verdict_action' => Admin_Page::ACTION_RECHECK,
                    'id'                 => (int) $item['id'],
                ),
                admin_url( 'tools.php' )
            ),
            Admin_Page::ACTION_RECHECK . '_' . (int) $item['id']
        );

        $acoes = array(
            'recheck' => sprintf(
                '<a href="%1$s">%2$s</a>',
                esc_url( $link_recheck ),
                esc_html__( 'Recheck', 'onsite-link-verdict' )
            ),
        );

        /*
         * A porta de entrada da versao Pro.
         *
         * Aparece a toda a gente de proposito, tambem a quem nao comprou: quem
         * carrega ve o ecra a explicar o que a Pro faz, com o endereco dele ja
         * la. Um convite no sitio onde a dor esta a acontecer vale mais do que
         * um banner no topo do painel — e nao interrompe ninguem.
         */
        $acoes['replace'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url( Upsell_Page::url_for( $item['url'] ) ),
            esc_html__( 'Replace everywhere', 'onsite-link-verdict' )
        );

        $marcacao = $completo === $texto
            ? esc_html( $texto )
            : sprintf( '<span title="%1$s">%2$s</span>', esc_attr( $completo ), esc_html( $texto ) );

        return $marcacao . $this->row_actions( $acoes );
    }

    public function column_default( $item, $coluna ) {

        switch ( $coluna ) {

            case 'url':
                return sprintf(
                    '<a href="%1$s" target="_blank" rel="noopener nofollow">%2$s</a>',
                    esc_url( $item['url'] ),
                    esc_html( $this->encurtar( $item['url'] ) )
                );

            case 'explicacao':
                return esc_html( $this->pagina->explain( $item['http_code'], $item['error'], $item['status'], $item['url'] ) );

            case 'origem':
                if ( 'post' !== $item['source_type'] ) {
                    return esc_html( $item['source_type'] );
                }

                $titulo = get_the_title( (int) $item['source_id'] );
                if ( '' === $titulo ) {
                    $titulo = __( '(no title)', 'onsite-link-verdict' );
                }

                // O get_edit_post_link() devolve null quando o artigo já não
                // existe (ou quando este utilizador não o pode editar). Passar
                // esse null ao esc_url() produzia <a href=""> mais uma
                // deprecação do PHP 8.1 (ltrim(): Passing null...). As linhas
                // órfãs já não deviam chegar aqui — os gatilhos de eliminação
                // apagam-nas —, mas quem desenha o painel não pode contar com
                // isso: basta uma linha ter ficado de uma versão anterior e a
                // página enche-se de avisos.
                $editar = get_edit_post_link( (int) $item['source_id'] );
                if ( ! $editar ) {
                    return esc_html( $titulo );
                }

                return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $editar ), esc_html( $titulo ) );

            case 'checked_at':
                return $item['checked_at']
                    ? esc_html( human_time_diff( strtotime( $item['checked_at'] ), time() ) . ' ' . __( 'ago', 'onsite-link-verdict' ) )
                    : esc_html__( 'not yet', 'onsite-link-verdict' );
        }

        return '';
    }

    public function no_items() {
        esc_html_e( 'Nothing to show here. Good news.', 'onsite-link-verdict' );
    }

    private function encurtar( $url, $max = 60 ) {
        return strlen( $url ) <= $max ? $url : substr( $url, 0, $max - 3 ) . '...';
    }
}
