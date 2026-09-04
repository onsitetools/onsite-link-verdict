<?php
/**
 * Autoloader próprio do plugin.
 *
 * O plugin publicado não leva vendor/, por isso não há autoloader do composer no
 * site do cliente — este é o único que lá corre. Vive num ficheiro seu, separado
 * do onsite-link-verdict.php, precisamente para poder ser carregado e exercitado por um
 * teste sem arrastar consigo o registo de todos os hooks do plugin.
 *
 * @package OnsiteLinkVerdict
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * ── 🔴 Porque é que o registo é POR PASTA e não uma vez só ────────────────────
 *
 * Descoberto a 21/08/2026, num ensaio com os dois pacotes instalados ao mesmo
 * tempo. É o momento em que alguém COMPRA: fica com a versão gratuita e a Pro
 * nas duas pastas, e o WordPress carrega-as por ordem alfabética — a gratuita
 * primeiro.
 *
 * Enquanto isto era uma função registada uma única vez, protegida por um
 * `function_exists()` global, o autoloader que ficava a valer era **o da
 * gratuita**, e ele só sabe procurar dentro da própria pasta. Resultado, medido
 * e não suposto: `Pro_Boot` e `Replacer` **não encontrados**, o portão a
 * responder que não há código da Pro, e o cliente que acabou de pagar a ver a
 * versão gratuita. Sem erro nenhum, sem aviso nenhum.
 *
 * Antes da regra 5 isto não acontecia — as duas pastas levavam todas as classes,
 * portanto tanto fazia qual delas ganhava. Foi a separação que abriu o buraco.
 *
 * A correcção: a tradução nome→ficheiro continua a ser uma função só (é lógica
 * pura, e é testável), mas **cada pasta regista o seu próprio autoloader**, com
 * a sua raiz fechada dentro dele. Duas pastas, duas procuras, cada uma na sua
 * casa.
 */

if ( ! function_exists( 'onsite_link_verdict_autoload_file' ) ) {

    /**
     * Traduz um nome de classe no ficheiro que a declara.
     *
     * \OnsiteLinkVerdict\Response_Classifier -> <raiz>/includes/class-response-classifier.php
     *
     * Está separada do registo para ser observável: uma função que devolve o
     * caminho pode ser testada a sério, enquanto uma que faz require silencioso
     * não deixa ver o que resolveu.
     *
     * ── Porque é que não basta o nome exacto ──────────────────────────────────
     *
     * Os ficheiros que só vão na versão paga levam um sufixo de empacotamento no
     * nome (é assim que o Freemius sabe o que tirar do pacote gratuito, e é o que
     * a regra 5 do repositório do WordPress.org obriga a fazer). No pacote pago
     * esses ficheiros chegam ao site COM o sufixo; no gratuito não chegam de todo.
     *
     * Por isso a procura é em duas voltas: primeiro o nome exacto, e só depois o
     * mesmo nome seguido de "__". A segunda volta não encontra nada no pacote
     * gratuito — e é isso que se quer: a classe não existe, e quem a chama tem de
     * perguntar primeiro (ver `Pro::is_active()`).
     *
     * O padrão leva "__" e não o sufixo escrito por extenso de propósito: o que
     * prepara os pacotes procura esse texto no código para decidir o que corta, e
     * um plugin que fala do sufixo dentro de si próprio é uma armadilha à espera.
     *
     * @param string      $class Nome completo da classe.
     * @param string|null $raiz  Pasta do plugin onde procurar. Sem ela, procura na
     *                           pasta de quem declarou esta função — que é o que
     *                           serve para os testes, onde só há uma cópia.
     * @return string Caminho do ficheiro, ou '' se a classe não é deste plugin ou
     *                não tem ficheiro correspondente.
     */
    function onsite_link_verdict_autoload_file( $class, $raiz = null ) {

        $class = ltrim( $class, '\\' );

        if ( 0 !== strpos( $class, 'OnsiteLinkVerdict\\' ) ) {
            return '';
        }

        $relativo = substr( $class, strlen( 'OnsiteLinkVerdict\\' ) );
        $ficheiro = 'class-' . strtolower( str_replace( '_', '-', $relativo ) );
        $base     = null === $raiz ? dirname( __DIR__ ) : $raiz;

        foreach ( array( 'includes', 'admin' ) as $pasta ) {
            $caminho = $base . '/' . $pasta . '/' . $ficheiro . '.php';
            if ( file_exists( $caminho ) ) {
                return $caminho;
            }
        }

        /*
         * Segunda volta. Nao se usa glob() de proposito: o caminho ate ao plugin
         * e' de quem instala, e um "[" ou um "{" no nome de uma pasta e' um
         * carater especial para o glob() — a procura falhava em silencio e a
         * versao paga ficava sem as suas classes, num site so, sem explicacao.
         */
        $prefixo = $ficheiro . '__';

        foreach ( array( 'includes', 'admin' ) as $pasta ) {

            $dir = $base . '/' . $pasta;

            foreach ( (array) @scandir( $dir ) as $nome ) {
                if ( 0 === strpos( $nome, $prefixo ) && '.php' === substr( $nome, -4 ) ) {
                    return $dir . '/' . $nome;
                }
            }
        }

        return '';
    }
}

/*
 * O registo, uma vez por pasta.
 *
 * A lista das raízes já registadas vive numa global porque tem de ser partilhada
 * entre duas cópias do mesmo ficheiro, em pastas diferentes, no mesmo pedido —
 * que é exactamente o caso que isto existe para resolver.
 */
if ( ! isset( $GLOBALS['onsite_link_verdict_raizes_registadas'] ) ) {
    $GLOBALS['onsite_link_verdict_raizes_registadas'] = array();
}

$onsite_link_verdict_raiz = dirname( __DIR__ );

if ( ! in_array( $onsite_link_verdict_raiz, $GLOBALS['onsite_link_verdict_raizes_registadas'], true ) ) {

    $GLOBALS['onsite_link_verdict_raizes_registadas'][] = $onsite_link_verdict_raiz;

    spl_autoload_register(
        function ( $class ) use ( $onsite_link_verdict_raiz ) {

            $ficheiro = onsite_link_verdict_autoload_file( $class, $onsite_link_verdict_raiz );

            if ( '' !== $ficheiro ) {
                require_once $ficheiro;
            }
        }
    );
}

unset( $onsite_link_verdict_raiz );
