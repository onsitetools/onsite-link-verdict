<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * O convite a avaliar o plugin no WordPress.org.
 *
 * ## Porque existe
 *
 * A 10/09/2026 o plugin tinha 0 avaliações, e as avaliações não são vaidade:
 * são um dos factores que sobem a posição na pesquisa do wp-admin, que é o
 * balcão principal. Sem elas o plugin fica preso — não é encontrado porque não
 * tem avaliações, e não tem avaliações porque não é encontrado.
 *
 * ## As regras que este desenho respeita, e porquê
 *
 * Um convite mal feito é pior do que nenhum: irrita, e um utilizador irritado
 * escreve uma avaliação de uma estrela sobre o convite em vez do produto.
 * Portanto:
 *
 * 1. **Só depois de o plugin ter feito o trabalho.** Nada aparece a quem
 *    acabou de instalar. É preciso um varrimento concluído com links
 *    verificados E {@see self::DIAS_MINIMOS} dias desde a activação. Pedir
 *    antes disso é pedir a alguém que avalie uma coisa que ainda não usou.
 * 2. **Uma vez.** Quem dispensa não volta a ver. Quem adia vê outra vez daqui
 *    a {@see self::DIAS_ADIAMENTO} dias, e só mais essa.
 * 3. **Nunca tapa nada nem bloqueia nada.** É um `notice` normal, dispensável.
 * 4. **Há uma saída para quem está descontente**, e é deliberada: o terceiro
 *    botão leva ao suporte. Quem tem um problema deve poder dizer-nos em vez
 *    de o escrever numa avaliação pública que não podemos responder nem
 *    resolver. É melhor para os dois lados.
 *
 * 🔴 O que este código NÃO faz, e não deve passar a fazer: prometer nada em
 * troca de uma avaliação. Isso é proibido pelas directrizes do WordPress.org
 * e, mesmo que não fosse, compraria avaliações em vez de as merecer.
 */
class Review_Prompt {

    /** Guarda o estado do convite: 'pendente', 'adiado', 'feito' ou 'dispensado'. */
    const OPTION_ESTADO = 'onsite_link_verdict_review_estado';

    /** Guarda o carimbo de tempo a partir do qual se pode voltar a mostrar. */
    const OPTION_QUANDO = 'onsite_link_verdict_review_quando';

    /** Guarda a data da primeira activação, para contar os dias de uso. */
    const OPTION_INSTALADO_EM = 'onsite_link_verdict_instalado_em';

    /** Acção do formulário dos botões. */
    const ACTION = 'onsite_link_verdict_review';

    /** Dias de uso antes de sequer pensar em convidar. */
    const DIAS_MINIMOS = 7;

    /** Dias a esperar quando a resposta é "agora não". */
    const DIAS_ADIAMENTO = 30;

    /** Links verificados que contam como "isto já fez alguma coisa por ti". */
    const LINKS_MINIMOS = 10;

    const URL_AVALIAR = 'https://wordpress.org/support/plugin/onsite-link-verdict/reviews/#new-post';
    const URL_SUPORTE = 'https://onsitetools.dev/support/';

    /** @var Store */
    private $store;

    public function __construct( Store $store ) {
        $this->store = $store;
    }

    /**
     * Regista a data de instalação, se ainda não houver.
     *
     * Corre na activação. Usa-se `add_option` de propósito: uma reactivação
     * não pode reiniciar a contagem, senão quem desactiva e reactiva o plugin
     * volta ao princípio e nunca chega aos dias mínimos.
     */
    public static function registar_instalacao() {
        add_option( self::OPTION_INSTALADO_EM, time(), '', false );
    }

    /**
     * Decide se o convite se mostra agora.
     *
     * @return bool
     */
    public function deve_mostrar() {

        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $estado = get_option( self::OPTION_ESTADO, 'pendente' );
        if ( 'feito' === $estado || 'dispensado' === $estado ) {
            return false;
        }

        $quando = (int) get_option( self::OPTION_QUANDO, 0 );
        if ( $quando > 0 && time() < $quando ) {
            return false;
        }

        $instalado = (int) get_option( self::OPTION_INSTALADO_EM, 0 );
        if ( $instalado <= 0 ) {
            /*
             * Sem data de instalação não se convida ninguém. Acontece a quem
             * já tinha o plugin antes de esta classe existir: a data passa a
             * contar a partir de agora, e daqui a DIAS_MINIMOS o convite
             * aparece. É de propósito -- o contrário seria mostrar o convite
             * imediatamente a toda a gente na actualização, que é exactamente
             * o comportamento que esta classe existe para evitar.
             */
            update_option( self::OPTION_INSTALADO_EM, time(), false );
            return false;
        }

        if ( ( time() - $instalado ) < ( self::DIAS_MINIMOS * DAY_IN_SECONDS ) ) {
            return false;
        }

        return $this->trabalho_feito();
    }

    /**
     * O plugin já fez o trabalho dele neste site?
     *
     * Contam os links que já têm veredicto — não os que estão em fila. Um
     * varrimento a meio não prova nada a ninguém.
     *
     * @return bool
     */
    private function trabalho_feito() {
        $contas = $this->store->count_by_status();

        $verificados = 0;
        foreach ( array( Response_Classifier::BROKEN, Response_Classifier::WARNING, Response_Classifier::UNVERIFIABLE, Response_Classifier::OK ) as $estado ) {
            if ( isset( $contas[ $estado ] ) ) {
                $verificados += (int) $contas[ $estado ];
            }
        }

        return $verificados >= self::LINKS_MINIMOS;
    }

    /**
     * Trata o clique num dos botões.
     *
     * @return string|false A resposta escolhida, ou false se não havia nada a tratar.
     */
    public function handle_action() {

        if ( ! isset( $_POST['onsite_link_verdict_review_resposta'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- o nonce é verificado a seguir.
            return false;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        /*
         * wp_verify_nonce e nao check_admin_referer, de proposito e por duas
         * razoes. A primeira e consistencia: e o que o resto do plugin usa. A
         * segunda pesa mais -- o check_admin_referer chama wp_die() quando o
         * nonce esta velho, e matar a pagina inteira do painel por causa de um
         * clique num convite dispensavel e' uma reaccao completamente
         * desproporcionada ao problema. Aqui, um nonce invalido nao faz nada.
         */
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
            return false;
        }

        $resposta = sanitize_key( wp_unslash( $_POST['onsite_link_verdict_review_resposta'] ) );

        switch ( $resposta ) {
            case 'sim':
                update_option( self::OPTION_ESTADO, 'feito', false );
                break;
            case 'depois':
                update_option( self::OPTION_ESTADO, 'adiado', false );
                update_option( self::OPTION_QUANDO, time() + ( self::DIAS_ADIAMENTO * DAY_IN_SECONDS ), false );
                break;
            case 'nunca':
            case 'problema':
                /*
                 * "Tenho um problema" também arruma o convite. Quem está a
                 * escrever-nos sobre um problema não deve levar com o pedido
                 * de avaliação outra vez enquanto espera pela resposta.
                 */
                update_option( self::OPTION_ESTADO, 'dispensado', false );
                break;
            default:
                return false;
        }

        return $resposta;
    }

    /**
     * Devolve o HTML do convite, já escapado.
     *
     * 🔴 SEM JAVASCRIPT, e é uma decisão. A forma habitual de fazer isto é uma
     * âncora com `onclick` a submeter o formulário por baixo, para que um
     * clique faça as duas coisas (abrir a página e arrumar o convite). Isso
     * tem uma corrida lá dentro: a submissão navega a página actual enquanto
     * o `target="_blank"` abre a outra, e quando o browser decide pela ordem
     * errada perde-se o clique e o utilizador vê o convite outra vez depois
     * de já ter escrito a avaliação. São quatro botões de formulário simples,
     * e quem carrega em "Write a review" leva um agradecimento com a ligação.
     * Um clique a mais, zero comportamentos por explicar.
     *
     * @return string
     */
    public function render() {

        $html  = '<div class="notice notice-info">';
        $html .= '<p><strong>' . esc_html__( 'Onsite Link Verdict has been checking your links for a while now.', 'onsite-link-verdict' ) . '</strong></p>';
        $html .= '<p>' . esc_html__( 'If it has been useful, a review on WordPress.org helps more than it looks: it is how other people find the plugin at all. It takes a minute and we read every one.', 'onsite-link-verdict' ) . '</p>';
        $html .= '<p>' . esc_html__( 'And if something is not working the way you expected, tell us instead. That we can actually fix.', 'onsite-link-verdict' ) . '</p>';

        $html .= '<form method="post" style="margin-bottom:1em;">';
        $html .= wp_nonce_field( self::ACTION, '_wpnonce', true, false );

        $html .= sprintf(
            '<button type="submit" name="onsite_link_verdict_review_resposta" value="sim" class="button button-primary">%s</button> ',
            esc_html__( 'Write a review', 'onsite-link-verdict' )
        );
        $html .= sprintf(
            '<button type="submit" name="onsite_link_verdict_review_resposta" value="problema" class="button">%s</button> ',
            esc_html__( 'I have a problem', 'onsite-link-verdict' )
        );
        $html .= sprintf(
            '<button type="submit" name="onsite_link_verdict_review_resposta" value="depois" class="button">%s</button>',
            esc_html__( 'Not now', 'onsite-link-verdict' )
        );
        $html .= sprintf(
            '<button type="submit" name="onsite_link_verdict_review_resposta" value="nunca" class="button-link" style="margin-left:1em;">%s</button>',
            esc_html__( 'Do not ask again', 'onsite-link-verdict' )
        );

        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Devolve o agradecimento com a ligação, depois de o utilizador escolher.
     *
     * @param string $resposta A resposta tratada por handle_action().
     * @return string HTML já escapado, ou string vazia se não houver nada a dizer.
     */
    public function render_seguimento( $resposta ) {

        if ( 'sim' === $resposta ) {
            return '<div class="notice notice-success"><p>'
                . esc_html__( 'Thank you. The review page opens in a new tab:', 'onsite-link-verdict' )
                . ' <a href="' . esc_url( self::URL_AVALIAR ) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__( 'write a review on WordPress.org', 'onsite-link-verdict' )
                . '</a>.</p></div>';
        }

        if ( 'problema' === $resposta ) {
            return '<div class="notice notice-success"><p>'
                . esc_html__( 'Tell us what happened and we will look at it:', 'onsite-link-verdict' )
                . ' <a href="' . esc_url( self::URL_SUPORTE ) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__( 'support', 'onsite-link-verdict' )
                . '</a>. This message will not come back.</p></div>';
        }

        return '';
    }
}
