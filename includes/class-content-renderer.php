<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * O conteúdo de um artigo tal como o visitante o vê.
 *
 * 🔴 **O `$post` global tem de estar montado antes do `the_content`.**
 *
 * Os construtores de páginas — Elementor, SiteOrigin, Beaver, Brizy — e muitos
 * shortcodes enganchados no `the_content` precisam de saber QUAL o artigo que
 * estão a desenhar, e perguntam-no ao `get_the_ID()`, que lê o `$post` global.
 * Fora do ciclo do WordPress esse global não existe, o `get_the_ID()` devolve
 * `false`, e eles devolvem o conteúdo tal como veio.
 *
 * Numa página do Elementor o `post_content` está **vazio** (o desenho vive todo
 * no `postmeta`). Sem esta preparação, o varrimento encontrava **zero** links
 * numa página cheia deles, e o utilizador recebia um "0 links partidos"
 * tranquilizador e falso. Verificado contra o Elementor e o SiteOrigin a sério a
 * 19/08/2026, no mesmo contexto em que o cron corre.
 *
 * Vive numa classe sua porque tem dois clientes: o Scanner, para encontrar os
 * links, e o Replacer, para confirmar depois de escrever que o endereço velho
 * deixou mesmo de aparecer na página.
 */
class Content_Renderer {

    /**
     * @param \WP_Post $post Artigo.
     * @return string HTML renderizado.
     */
    public function render( $post ) {

        if ( ! $post ) {
            return '';
        }

        $anterior = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

        $GLOBALS['post'] = $post;
        setup_postdata( $post );

        /*
         * O `the_content` é do WordPress, não nosso — estamos a CORRER o filtro
         * do core para ver o conteúdo como o visitante o vê, que é a razão de
         * ser desta classe. A ferramenta de revisão marca isto como "hook sem
         * prefixo do plugin"; é um falso alarme, e fica aqui dito para ninguém
         * ter de o descobrir outra vez.
         */
        $html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        /*
         * O global anterior é reposto à mão, e não pelo `wp_reset_postdata()`:
         * esse repõe a partir da consulta principal, que no cron não existe —
         * deixaria o global a `null` e partia quem viesse a seguir na fila.
         */
        if ( null === $anterior ) {
            unset( $GLOBALS['post'] );
        } else {
            $GLOBALS['post'] = $anterior;
            setup_postdata( $anterior );
        }

        return $html;
    }

    /**
     * O mesmo, a partir de um id, com o objeto lido de fresco.
     *
     * @param int $post_id Id do artigo.
     * @return string
     */
    public function render_id( $post_id ) {
        clean_post_cache( (int) $post_id );
        return $this->render( get_post( (int) $post_id ) );
    }
}
