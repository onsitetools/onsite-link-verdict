<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * O convite para a versão paga, no sítio onde a dor está a acontecer.
 *
 * Cada linha da lista tem uma acção "Replace everywhere". Ela aponta para aqui,
 * com o endereço do utilizador já no pedido. No pacote gratuito é ESTA página que
 * responde: explica o que a Pro faz, e mais nada. No pacote pago, o `Replace_Page`
 * toma o lugar no mesmo endereço e faz o trabalho.
 *
 * ── Porque é que isto existe como classe à parte ──────────────────────────────
 *
 * Por causa da regra 5 do repositório do WordPress.org: o pacote gratuito não
 * pode levar o código da funcionalidade paga. Até 20/08/2026 era o próprio
 * `Replace_Page` que mostrava o convite quando não havia licença — o que obrigava
 * a que ele fosse no pacote gratuito, com a máquina toda lá dentro, fechada à
 * chave. Era exactamente o que a regra proíbe.
 *
 * Fazer upsell **é permitido**. O que não pode é o código fechado. Daí a divisão:
 * o convite fica aqui, no gratuito; a máquina vai num ficheiro que o pacote
 * gratuito não leva.
 *
 * ⚠️ O `MENU_SLUG` tem de ser o MESMO do `Replace_Page` — é o endereço para onde
 * a lista aponta nos dois pacotes. Há um teste a exigir que sejam iguais, porque
 * a alternativa é uma acção de linha que dá "não tem permissão" no dia em que
 * alguém mudar um dos dois.
 */
class Upsell_Page {

    const MENU_SLUG = 'onsite-link-verdict-replace';

    /**
     * Regista a página. Não aparece no menu — chega-se-lhe pela acção de linha.
     *
     * @return void
     */
    public function register() {
        add_submenu_page(
            '',
            __( 'Replace a URL', 'onsite-link-verdict' ),
            __( 'Replace a URL', 'onsite-link-verdict' ),
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render' )
        );
    }

    /**
     * O endereço da página, para um URL concreto.
     *
     * É daqui que a lista tira o link, nos dois pacotes: o endereço é o mesmo,
     * mude ou não quem o atende do outro lado.
     *
     * @param string $url URL a substituir.
     * @return string
     */
    public static function url_for( $url ) {
        return admin_url( 'tools.php?page=' . self::MENU_SLUG . '&url=' . rawurlencode( $url ) );
    }

    /**
     * @return void
     */
    public function render() {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'onsite-link-verdict' ) );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Replace a URL across the site', 'onsite-link-verdict' ) . '</h1>';
        echo '<p>' . esc_html__( 'Replacing a URL everywhere at once is part of Onsite Link Verdict Pro.', 'onsite-link-verdict' ) . '</p>';
        echo '<p>' . esc_html__( 'The free version finds the broken links and tells you exactly which post or comment each one is in, so you can fix them in the editor. Pro does the mechanical part for you: it shows you every place a URL appears, lets you check the list, replaces it in one go, and keeps an undo.', 'onsite-link-verdict' ) . '</p>';
        echo '</div>';
    }
}
