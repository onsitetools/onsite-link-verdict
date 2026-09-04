<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Limpeza do que vem de um formulario, antes de tocar na base de dados.
 *
 * 🔴 **Porque e que isto e uma classe a parte, e nao um metodo da Exclusions.**
 *
 * A `Exclusions` so existe na versao paga, e o ficheiro dela e cortado do pacote
 * gratuito. Se a limpeza vivesse la dentro, quem le o pacote gratuito — e e esse
 * que o revisor do WordPress.org le — via a entrada do formulario a ser entregue
 * a uma funcao que nao esta no pacote. Nao da para verificar o que nao se ve, e
 * uma limpeza que nao se consegue verificar vale zero.
 *
 * Esta classe viaja nos dois pacotes. A limpeza esta a vista de quem quiser
 * conferir.
 */
class Sanitizer {

    /**
     * Limpa uma caixa de texto com padroes de endereco, um por linha.
     *
     * 🔴 **NAO se usa a `sanitize_textarea_field()`, e a razao importa.** Essa
     * funcao APAGA as sequencias `%XX` do texto. Isto sao padroes de ENDERECO, e
     * um endereco leva `%20`, `%C3%A9`, `%2F` — a limpeza do costume corrompia a
     * regra, guardava-a na mesma e nao dava erro nenhum. A pessoa escrevia uma
     * coisa e ficava guardada outra, que e exactamente o comportamento contra o
     * qual este plugin se posiciona. Ha testes so para guardar isto.
     *
     * Pela mesma razao nao se colapsam as quebras de linha: uma regra por linha
     * e a sintaxe, e junta-las transformava trinta regras numa.
     *
     * O que sai, entao:
     *
     * - texto que nao seja UTF-8 valido;
     * - marcacao HTML (um padrao de endereco nunca precisa de uma etiqueta, e um
     *   `<script>` guardado e uma bomba armada a espera do dia em que alguem o
     *   imprima sem escapar);
     * - bytes de controlo.
     *
     * @param string $texto Conteudo da caixa de texto.
     * @return string O mesmo texto, sem marcacao nem bytes de controlo.
     */
    public static function address_patterns( $texto ) {

        $texto = wp_check_invalid_utf8( (string) $texto );

        $linhas = preg_split( '/\R/', $texto );
        $saida  = array();

        foreach ( $linhas as $linha ) {

            $linha = wp_strip_all_tags( $linha, true );

            /*
             * Sem o modificador `/u`, de proposito: as gamas sao ASCII de um
             * byte, e no UTF-8 os bytes de continuacao sao todos >= 0x80. Uma
             * substituicao byte a byte e segura aqui — e, ao contrario da versao
             * com `/u`, nao devolve null (ou seja, nao apaga o texto todo) se
             * alguma sequencia estranha tiver escapado a verificacao acima.
             */
            $linha = preg_replace( '/[\x00-\x1F\x7F]/', '', $linha );

            $saida[] = $linha;
        }

        return implode( "\n", $saida );
    }
}
