<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Extrai de um bloco de HTML os links e imagens que vale a pena verificar.
 * PHP puro. Usa DOMDocument porque expressões regulares em HTML de utilizador
 * falham sempre, mais cedo ou mais tarde.
 */
class Link_Extractor {

    /** Esquemas que não se verificam por HTTP. */
    private static $esquemas_ignorados = array( 'mailto', 'tel', 'javascript', 'data', 'sms', 'ftp', 'file' );

    /**
     * @param string $html     Conteúdo a analisar.
     * @param string $base_url URL da página onde este conteúdo vive (para resolver relativos).
     * @return array Lista de array( 'url' => string, 'anchor' => string, 'type' => 'link'|'image' ).
     */
    public function extract( $html, $base_url ) {

        if ( '' === trim( (string) $html ) ) {
            return array();
        }

        $doc = new \DOMDocument();

        // O HTML dos utilizadores é quase sempre inválido. Calamos os avisos de propósito.
        $anterior = libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        libxml_use_internal_errors( $anterior );

        $resultado = array();
        $vistos    = array();

        $fontes = array(
            array( 'tag' => 'a',   'attr' => 'href', 'type' => 'link',  'texto' => 'texto' ),
            array( 'tag' => 'img', 'attr' => 'src',  'type' => 'image', 'texto' => 'alt' ),
        );

        foreach ( $fontes as $fonte ) {
            $nos = $doc->getElementsByTagName( $fonte['tag'] );

            foreach ( $nos as $no ) {
                $bruto = trim( (string) $no->getAttribute( $fonte['attr'] ) );
                $url   = $this->normalizar( $bruto, $base_url );

                if ( null === $url || isset( $vistos[ $url ] ) ) {
                    continue;
                }

                $vistos[ $url ] = true;

                $ancora = 'alt' === $fonte['texto']
                    ? trim( (string) $no->getAttribute( 'alt' ) )
                    : trim( (string) $no->textContent );

                $resultado[] = array(
                    'url'    => $url,
                    'anchor' => $ancora,
                    'type'   => $fonte['type'],
                );
            }
        }

        return $resultado;
    }

    /**
     * Devolve o URL absoluto e sem fragmento, ou null se não houver nada para verificar.
     */
    private function normalizar( $bruto, $base_url ) {

        if ( '' === $bruto || 0 === strpos( $bruto, '#' ) ) {
            return null;
        }

        // Corta o fragmento: não muda se a página existe.
        $posicao = strpos( $bruto, '#' );
        if ( false !== $posicao ) {
            $bruto = substr( $bruto, 0, $posicao );
            if ( '' === trim( $bruto ) ) {
                return null;
            }
        }

        $partes = wp_parse_url( $bruto );
        if ( false === $partes ) {
            return null;
        }

        if ( isset( $partes['scheme'] ) ) {
            if ( in_array( strtolower( $partes['scheme'] ), self::$esquemas_ignorados, true ) ) {
                return null;
            }
            return $bruto;
        }

        $base = wp_parse_url( $base_url );
        if ( ! isset( $base['scheme'], $base['host'] ) ) {
            return null;
        }

        // URL sem esquema: //cdn.exemplo.pt/f.js
        if ( 0 === strpos( $bruto, '//' ) ) {
            return $base['scheme'] . ':' . $bruto;
        }

        $raiz = $base['scheme'] . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );

        // Caminho absoluto: /contactos
        if ( 0 === strpos( $bruto, '/' ) ) {
            return $raiz . $bruto;
        }

        // Caminho relativo: ../outra/
        $dir      = isset( $base['path'] ) ? $base['path'] : '/';
        $dir      = '/' === substr( $dir, -1 ) ? $dir : dirname( $dir ) . '/';
        $juntos   = $dir . $bruto;
        $segmentos = array();

        foreach ( explode( '/', $juntos ) as $segmento ) {
            if ( '.' === $segmento || '' === $segmento ) {
                continue;
            }
            if ( '..' === $segmento ) {
                array_pop( $segmentos );
                continue;
            }
            $segmentos[] = $segmento;
        }

        $caminho = '/' . implode( '/', $segmentos );

        // Preserva a barra final, que em WordPress distingue páginas.
        if ( '/' === substr( $juntos, -1 ) && '/' !== substr( $caminho, -1 ) ) {
            $caminho .= '/';
        }

        return $raiz . $caminho;
    }
}
