<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * O plugin visto por um assistente de IA: três "abilities" do WordPress.
 *
 * ## Porque existe
 *
 * Desde o WordPress 6.9 um plugin pode declarar o que sabe fazer numa forma que
 * os assistentes ligados ao site entendem (a Abilities API do core; o plugin
 * MCP Adapter da equipa do WordPress expõe-nas a clientes como o Claude ou o
 * ChatGPT). Quem mantém sites de clientes — o nosso comprador — passa a poder
 * perguntar "há links partidos no site?" ao assistente, em vez de abrir o painel.
 *
 * ## As três, e porque são três
 *
 * Uma por pergunta que uma pessoa faria, não uma por consulta à base de dados
 * (é a regra do guia oficial, `WordPress/agent-skills`, wp-abilities-api):
 *
 * - `get-summary` — "como estão os links do site?" Sem argumentos.
 * - `get-links`   — "mostra-me os partidos" (ou os de outro separador).
 * - `start-scan`  — "verifica o site inteiro". O mesmo que o botão do painel.
 *
 * ## O que NÃO fazem, de propósito
 *
 * - **Não verificam nada na hora.** O start-scan só põe em fila, como o botão.
 *   Um assistente que pudesse mandar verificar mil links dentro de um pedido
 *   faria exactamente o que este plugin existe para não fazer: pôr o servidor a
 *   trabalhar à frente de alguém.
 * - **Não mexem no conteúdo.** Nenhuma escreve em artigos. Substituir links é da
 *   Pro e fica fora daqui.
 * - **Não inventam outra explicação.** O texto de cada resultado sai do mesmo
 *   Admin_Page::explain() que o painel mostra; o veredicto do assistente e o do
 *   ecrã são a mesma frase.
 *
 * Só existem onde o WordPress tem a Abilities API (6.9+). Abaixo disso os
 * hooks nunca disparam e nada disto corre — não é preciso guarda de versão.
 *
 * Quem mexer no código por trás de uma destas (o painel, o Store, o botão),
 * confirma se a ability tem de acompanhar.
 */
class Abilities {

    const CATEGORY = 'onsite-link-verdict';

    const SUMMARY = 'onsite-link-verdict/get-summary';
    const LINKS   = 'onsite-link-verdict/get-links';
    const SCAN    = 'onsite-link-verdict/start-scan';

    /** Quantos links o get-links devolve por omissão, e o tecto. */
    const LINKS_DEFAULT = 20;
    const LINKS_MAX     = 100;

    /** O que o painel exige para se ver. As abilities não pedem menos. */
    const CAPABILITY = 'manage_options';

    public static function register_category() {
        if ( ! function_exists( 'wp_register_ability_category' ) ) {
            return;
        }
        wp_register_ability_category(
            self::CATEGORY,
            array(
                'label'       => __( 'Onsite Link Verdict', 'onsite-link-verdict' ),
                'description' => __( 'Broken link checking that runs on this site.', 'onsite-link-verdict' ),
            )
        );
    }

    public static function register() {

        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        $leitura = array(
            'show_in_rest' => true,
            'mcp'          => array( 'public' => true ),
            'annotations'  => array(
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ),
        );

        wp_register_ability(
            self::SUMMARY,
            array(
                'label'               => __( 'Broken link summary', 'onsite-link-verdict' ),
                'description'         => __( 'How many links on this site are broken, in warning, unverifiable, good or still waiting to be checked, and whether background checking can run. A link is only counted as broken when the server says the page is gone (404 or 410) or nothing answers at all; servers that refuse robots are counted as unverifiable, not broken.', 'onsite-link-verdict' ),
                'category'            => self::CATEGORY,
                'execute_callback'    => array( __CLASS__, 'get_summary' ),
                'permission_callback' => array( __CLASS__, 'can_use' ),
                'output_schema'       => self::summary_schema(),
                'meta'                => $leitura,
            )
        );

        wp_register_ability(
            self::LINKS,
            array(
                'label'               => __( 'List checked links', 'onsite-link-verdict' ),
                'description'         => __( 'Lists the links in one result group (broken by default), each with its address, the plain-language explanation of the result, and the post, page or comment where it appears, with a link to edit it.', 'onsite-link-verdict' ),
                'category'            => self::CATEGORY,
                'execute_callback'    => array( __CLASS__, 'get_links' ),
                'permission_callback' => array( __CLASS__, 'can_use' ),
                'input_schema'        => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'status' => array(
                            'type'        => 'string',
                            'enum'        => self::estados(),
                            'default'     => Response_Classifier::BROKEN,
                            'description' => __( 'Which result group: broken, warning, unverifiable or ok.', 'onsite-link-verdict' ),
                        ),
                        'limit'  => array(
                            'type'    => 'integer',
                            'minimum' => 1,
                            'maximum' => self::LINKS_MAX,
                            'default' => self::LINKS_DEFAULT,
                        ),
                        'offset' => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                            'default' => 0,
                        ),
                    ),
                    'additionalProperties' => false,
                ),
                'output_schema'       => self::links_schema(),
                'meta'                => $leitura,
            )
        );

        wp_register_ability(
            self::SCAN,
            array(
                'label'               => __( 'Start a full-site link scan', 'onsite-link-verdict' ),
                'description'         => __( 'Queues every post, page and comment for a fresh link scan, the same as the "Start scan" button. Nothing is checked during this request: the checking runs in small background batches, so results arrive over the following minutes or hours. Use the summary to follow progress.', 'onsite-link-verdict' ),
                'category'            => self::CATEGORY,
                'execute_callback'    => array( __CLASS__, 'start_scan' ),
                'permission_callback' => array( __CLASS__, 'can_use' ),
                'output_schema'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'scheduled'       => array( 'type' => 'boolean' ),
                        'already_queued'  => array( 'type' => 'boolean' ),
                        'message'         => array( 'type' => 'string' ),
                    ),
                ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'mcp'          => array( 'public' => true ),
                    'annotations'  => array(
                        // Escreve na fila de cron, mais nada: não é leitura,
                        // não apaga nada, e pedir duas vezes dá o mesmo que uma.
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                ),
            )
        );
    }

    /**
     * Quem vê o painel pode usar as abilities; quem não vê, não.
     *
     * @param mixed $input Ignorado.
     * @return bool
     */
    public static function can_use( $input = null ) {
        return current_user_can( self::CAPABILITY );
    }

    /**
     * @param mixed $input Ignorado (a ability não tem argumentos).
     * @return array
     */
    public static function get_summary( $input = null ) {

        $contas = ( new Store() )->count_by_status();
        $cron   = new Cron();

        $total       = array_sum( array_map( 'intval', $contas ) );
        $verificados = $total - (int) $contas['pending'];

        return array(
            'broken'                   => (int) $contas[ Response_Classifier::BROKEN ],
            'warning'                  => (int) $contas[ Response_Classifier::WARNING ],
            'unverifiable'             => (int) $contas[ Response_Classifier::UNVERIFIABLE ],
            'ok'                       => (int) $contas[ Response_Classifier::OK ],
            'pending'                  => (int) $contas['pending'],
            'total'                    => $total,
            'checked_percent'          => $total > 0 ? (int) floor( 100 * $verificados / $total ) : 0,
            'full_scan_queued'         => (bool) wp_next_scheduled( Cron::HOOK_QUEUE_ALL ),
            'wp_cron_disabled'         => $cron->is_real_cron_disabled(),
            'speed'                    => $cron->speed(),
            'admin_url'                => admin_url( 'tools.php?page=' . Admin_Page::MENU_SLUG ),
        );
    }

    /**
     * @param array|null $input status / limit / offset, já validados pelo schema.
     * @return array
     */
    public static function get_links( $input = null ) {

        $input  = is_array( $input ) ? $input : array();
        $status = isset( $input['status'] ) && in_array( $input['status'], self::estados(), true )
            ? $input['status']
            : Response_Classifier::BROKEN;
        $limit  = isset( $input['limit'] ) ? max( 1, min( self::LINKS_MAX, (int) $input['limit'] ) ) : self::LINKS_DEFAULT;
        $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;

        $store  = new Store();
        $pagina = new Admin_Page( $store );
        $linhas = $store->get_by_status( $status, $limit, $offset );
        $contas = $store->count_by_status();

        $links = array();
        foreach ( (array) $linhas as $linha ) {
            $links[] = array(
                'url'         => (string) $linha['url'],
                'anchor'      => (string) $linha['anchor'],
                'status'      => (string) $linha['status'],
                'http_code'   => null === $linha['http_code'] ? null : (int) $linha['http_code'],
                'explanation' => $pagina->explain( $linha['http_code'], $linha['error'], $linha['status'], $linha['url'] ),
                'found_in'    => self::origem( $linha ),
                'checked_at'  => $linha['checked_at'] ? mysql_to_rfc3339( $linha['checked_at'] ) : null,
            );
        }

        return array(
            'status' => $status,
            'total'  => (int) $contas[ $status ],
            'offset' => $offset,
            'links'  => $links,
        );
    }

    /**
     * @param mixed $input Ignorado.
     * @return array
     */
    public static function start_scan( $input = null ) {

        $ja_havia = ( new Cron() )->request_full_scan();

        return array(
            'scheduled'      => true,
            'already_queued' => $ja_havia,
            'message'        => __( 'Scan scheduled. Links will be checked in the background.', 'onsite-link-verdict' ),
        );
    }

    /**
     * Onde o link aparece. Mesma lógica da coluna "Where it is" do painel.
     *
     * @param array $linha Linha do Store.
     * @return array
     */
    private static function origem( $linha ) {

        $tipo = (string) $linha['source_type'];
        $id   = (int) $linha['source_id'];

        if ( 'comment' === $tipo ) {
            $comentario = get_comment( $id );
            return array(
                'type'     => 'comment',
                'id'       => $id,
                'title'    => $comentario ? get_the_title( (int) $comentario->comment_post_ID ) : '',
                'edit_url' => $comentario ? (string) get_edit_comment_link( $id ) : '',
            );
        }

        return array(
            'type'     => $tipo,
            'id'       => $id,
            'title'    => 'post' === $tipo ? get_the_title( $id ) : '',
            // null quando o artigo já não existe ou este utilizador não o edita.
            'edit_url' => 'post' === $tipo ? (string) get_edit_post_link( $id, 'raw' ) : '',
        );
    }

    /** Os quatro separadores do painel, pela ordem do painel. */
    private static function estados() {
        return array(
            Response_Classifier::BROKEN,
            Response_Classifier::WARNING,
            Response_Classifier::UNVERIFIABLE,
            Response_Classifier::OK,
        );
    }

    private static function summary_schema() {
        $inteiro = array( 'type' => 'integer' );
        return array(
            'type'       => 'object',
            'properties' => array(
                'broken'           => $inteiro,
                'warning'          => $inteiro,
                'unverifiable'     => $inteiro,
                'ok'               => $inteiro,
                'pending'          => $inteiro,
                'total'            => $inteiro,
                'checked_percent'  => $inteiro,
                'full_scan_queued' => array( 'type' => 'boolean' ),
                'wp_cron_disabled' => array( 'type' => 'boolean' ),
                'speed'            => array( 'type' => 'string' ),
                'admin_url'        => array( 'type' => 'string' ),
            ),
        );
    }

    private static function links_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'status' => array( 'type' => 'string' ),
                'total'  => array( 'type' => 'integer' ),
                'offset' => array( 'type' => 'integer' ),
                'links'  => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'url'         => array( 'type' => 'string' ),
                            'anchor'      => array( 'type' => 'string' ),
                            'status'      => array( 'type' => 'string' ),
                            'http_code'   => array( 'type' => array( 'integer', 'null' ) ),
                            'explanation' => array( 'type' => 'string' ),
                            'found_in'    => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'type'     => array( 'type' => 'string' ),
                                    'id'       => array( 'type' => 'integer' ),
                                    'title'    => array( 'type' => 'string' ),
                                    'edit_url' => array( 'type' => 'string' ),
                                ),
                            ),
                            'checked_at'  => array( 'type' => array( 'string', 'null' ) ),
                        ),
                    ),
                ),
            ),
        );
    }
}
