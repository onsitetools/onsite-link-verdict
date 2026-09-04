<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Faz o pedido HTTP a um URL e devolve um veredito.
 *
 * Regras que existem para não maltratar nem o site do cliente nem os sites de destino:
 *  - HEAD primeiro (não descarrega o corpo). Só cai para GET se o servidor recusar.
 *  - Tempo-limite curto: mais vale um "aviso" do que prender o cron durante minutos.
 *  - Redirecionamentos seguidos à mão, para detetar ciclos e saber onde a cadeia acabou.
 */
class Checker {

    /** Segundos até desistir de um pedido. */
    const TIMEOUT = 12;

    /** Redirecionamentos seguidos antes de desistir. */
    const MAX_REDIRECTS = 5;

    /**
     * Bytes de corpo que aceitamos receber num pedido.
     *
     * Só interessa o código de estado — o corpo nunca é lido. Sem este teto, um
     * servidor que recuse HEAD (405/501) faz-nos cair para GET, e o
     * wp_remote_request() traz o corpo COMPLETO para memória: um link para um PDF
     * ou vídeo de 500 MB num CDN rápido, dentro dos 12 segundos de tempo-limite,
     * são centenas de MB dentro do pedido de cron do cliente. Nada se perde ao
     * cortar aqui.
     */
    const MAX_BODY_BYTES = 2048;

    /** @var Response_Classifier */
    private $classifier;

    public function __construct( $classifier = null ) {
        $this->classifier = $classifier ? $classifier : new Response_Classifier();
    }

    /**
     * @param string $url URL absoluto.
     * @return array
     */
    public function check( $url ) {

        $atual      = $url;
        $visitados  = array();
        $erro       = '';
        $codigo     = null;
        $cabecalhos = array();

        for ( $salto = 0; $salto <= self::MAX_REDIRECTS; $salto++ ) {

            if ( isset( $visitados[ $atual ] ) ) {
                $erro = 'redirect_loop';
                break;
            }
            $visitados[ $atual ] = true;

            $resposta = $this->pedir( 'HEAD', $atual );

            // Alguns servidores não respondem a HEAD. Tentamos GET antes de desistir.
            if ( null !== $resposta['http_code'] && in_array( $resposta['http_code'], array( 405, 501 ), true ) ) {
                $resposta = $this->pedir( 'GET', $atual );
            }

            /*
             * NUNCA acusar um link com base num HEAD sozinho.
             *
             * O 404 e o 410 são os únicos vereditos imediatos do plugin: não
             * passam pela série de três falhas, não têm segunda oportunidade.
             * Isso torna-os o sítio onde um engano custa mais — e há servidores
             * que respondem 404 ao HEAD e 200 ao GET. A zazzle.com faz
             * exactamente isto (verificado contra o site real a 19/08/2026) e é
             * um caso real e reproduzível deste comportamento.
             * Sem esta confirmação, acusávamos um link vivo com a máxima
             * confiança possível.
             *
             * O pedido extra só se paga onde vale a pena: no momento de acusar.
             * Um link saudável continua a custar um único HEAD, e o corpo do GET
             * continua limitado pelo MAX_BODY_BYTES.
             */
            if ( '' === $resposta['error'] && in_array( (int) $resposta['http_code'], array( 404, 410 ), true ) ) {
                $confirmacao = $this->pedir( 'GET', $atual );

                // Só se aceita a confirmação se ela própria correu bem. Um erro
                // de ligação no segundo pedido não pode apagar o 404 do primeiro.
                if ( '' === $confirmacao['error'] && null !== $confirmacao['http_code'] ) {
                    $resposta = $confirmacao;
                }
            }

            $erro       = $resposta['error'];
            $codigo     = $resposta['http_code'];
            $cabecalhos = $resposta['headers'];

            if ( '' !== $erro ) {
                break;
            }

            if ( $codigo >= 300 && $codigo < 400 && '' !== $resposta['location'] ) {
                $atual = $this->resolver_location( $resposta['location'], $atual );
                continue;
            }

            break;
        }

        if ( $salto > self::MAX_REDIRECTS && '' === $erro ) {
            $erro = 'too_many_redirects';
        }

        return array(
            'status'     => $this->classifier->classify( $codigo, $erro, $cabecalhos ),
            'http_code'  => $codigo,
            'error'      => $erro,
            'final_url'  => $atual,
            'headers'    => $cabecalhos,
            'checked_at' => time(),
        );
    }

    /**
     * Um pedido, sem seguir redirecionamentos.
     */
    private function pedir( $metodo, $url ) {

        $args = array(
            'method'      => $metodo,
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'sslverify'   => true,
            // Só faz diferença no GET (o HEAD não traz corpo nenhum), mas vai em
            // todos os pedidos para não haver caminho por onde escape.
            'limit_response_size' => self::MAX_BODY_BYTES,
            'user-agent'  => 'Onsite Link Verdict/' . ( defined( 'ONSITE_LINK_VERDICT_VERSION' ) ? ONSITE_LINK_VERDICT_VERSION : 'dev' ) . '; ' . home_url( '/' ),
            'headers'     => array( 'Accept' => '*/*' ),
        );

        $r = wp_remote_request( $url, $args );

        if ( is_wp_error( $r ) ) {
            return array(
                'http_code' => null,
                'error'     => $this->traduzir_erro( $r ),
                'location'  => '',
                'headers'   => array(),
            );
        }

        return array(
            'http_code' => (int) wp_remote_retrieve_response_code( $r ),
            'error'     => '',
            'location'  => (string) wp_remote_retrieve_header( $r, 'location' ),
            'headers'   => $this->cabecalhos_em_array( $r ),
        );
    }

    /**
     * Os cabecalhos da resposta como array simples.
     *
     * O wp_remote_retrieve_headers() nao devolve um array: devolve um objeto
     * (Requests_Utility_CaseInsensitiveDictionary nas versoes antigas do
     * WordPress, WpOrg\Requests\Utility\CaseInsensitiveDictionary nas novas).
     * Passa-lo direto ao classificador funcionava por acaso no foreach e partia-se
     * no is_array(). Normaliza-se aqui, uma vez, no sitio onde se sabe de onde veio.
     *
     * Isto nao acrescenta trafego nenhum: os cabecalhos ja vinham na resposta e
     * estavam a ser deitados fora.
     *
     * @param array|object $r Resposta do wp_remote_request().
     * @return array
     */
    private function cabecalhos_em_array( $r ) {

        $h = wp_remote_retrieve_headers( $r );

        if ( is_object( $h ) && method_exists( $h, 'getAll' ) ) {
            return (array) $h->getAll();
        }

        if ( is_object( $h ) ) {
            return (array) $h;
        }

        return is_array( $h ) ? $h : array();
    }

    /**
     * Traduz o erro do WordPress/cURL num dos tokens que o classificador conhece.
     */
    private function traduzir_erro( $wp_error ) {

        $msg = strtolower( $wp_error->get_error_message() );

        if ( false !== strpos( $msg, 'could not resolve host' )
            || false !== strpos( $msg, 'name or service not known' )
            || false !== strpos( $msg, 'resolving timed out' )
            || false !== strpos( $msg, 'temporary failure in name resolution' ) ) {
            return 'dns';
        }

        if ( false !== strpos( $msg, 'timed out' ) || false !== strpos( $msg, 'timeout' ) ) {
            return 'timeout';
        }

        if ( false !== strpos( $msg, 'ssl' ) || false !== strpos( $msg, 'certificate' ) ) {
            return 'ssl';
        }

        return 'connection';
    }

    /**
     * O cabeçalho Location pode vir relativo.
     */
    private function resolver_location( $location, $url_atual ) {

        if ( preg_match( '#^https?://#i', $location ) ) {
            return $location;
        }

        $base = wp_parse_url( $url_atual );
        if ( ! isset( $base['scheme'], $base['host'] ) ) {
            return $location;
        }

        $raiz = $base['scheme'] . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );

        if ( 0 === strpos( $location, '/' ) ) {
            return $raiz . $location;
        }

        $dir = isset( $base['path'] ) ? $base['path'] : '/';

        // Só se sobe ao dirname() quando o caminho não é já um "directório" (não acaba em "/").
        // O dirname() de um caminho com um só segmento (ex: "/redirect-chain") devolve "/" —
        // e essa barra já chega; não se lhe acrescenta outra por cima.
        if ( '/' !== substr( $dir, -1 ) ) {
            $dir = dirname( $dir );
            $dir = '/' === substr( $dir, -1 ) ? $dir : $dir . '/';
        }

        return $raiz . $dir . $location;
    }
}
