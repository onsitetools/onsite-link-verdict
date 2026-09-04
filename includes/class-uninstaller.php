<?php
namespace OnsiteLinkVerdict;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A limpeza da desinstalação: não deixar nada para trás.
 *
 * ── Porque é que isto NÃO está num `uninstall.php` ────────────────────────
 *
 * Estava, e teve de sair a 20/08/2026, quando o Freemius entrou. Duas razões,
 * e a segunda é a que morde:
 *
 * 1. O Freemius recusa publicar um plugin que traga `uninstall.php`. O motivo
 *    deles é o registo do evento de desinstalação: com esse ficheiro presente,
 *    o WordPress executa-o e o evento nunca é reportado.
 *
 * 2. 🔴 O `register_uninstall_hook()` do WordPress guarda **um único** callback
 *    por ficheiro de plugin. O Freemius já lá tem o dele. Se nós registássemos
 *    o nosso, apagávamos o deles — e ao contrário. Não há aqui espaço para dois,
 *    e por isso a limpeza pendura-se na acção `after_uninstall` do SDK, que é o
 *    sítio que eles abriram exactamente para isto.
 *
 * ⚠️ A consequência, dita em voz alta: **sem o SDK carregado, ninguém chama
 * isto.** Na prática o SDK vai sempre no pacote — mas quem alguma vez o tirar
 * fica sem limpeza, e é o tipo de coisa que ninguém dá por ela até um cliente
 * apagar o plugin e ficar com as tabelas lá.
 */
class Uninstaller {

    /**
     * Apaga tabelas, opções e agendamentos deste plugin.
     *
     * @return void
     */
    public static function run() {

        ( new Store() )->drop();

        /*
         * A tabela de operações é da versão Pro, e aqui trata-se dela pelo nome
         * em vez de pela classe. Não é preguiça: no pacote gratuito a classe
         * `Ops_Store` **não existe** (regra 5 — o código da Pro não vai lá
         * dentro), e quem teve a Pro, voltou à gratuita e depois apaga o plugin
         * ficava com a tabela e a opção no lugar. Uma limpeza que só limpa em
         * metade dos casos é do mesmo tipo do que este produto existe para não
         * ter.
         *
         * O DROP TABLE IF EXISTS trata do caso de nunca ter existido. Há um
         * teste a exigir que estes dois nomes continuem iguais aos da `Ops_Store`
         * e da `Exclusions`, para não poderem afastar-se em silêncio.
         */
        global $wpdb;

        $tabela_ops = $wpdb->prefix . 'onsite_link_verdict_ops';
        $wpdb->query( "DROP TABLE IF EXISTS {$tabela_ops}" ); // phpcs:ignore -- nome de tabela nosso, montado a partir do prefixo do WordPress; nao entra nada de fora e nao ha o que preparar.

        ( new Cron() )->unschedule();

        delete_option( 'onsite_link_verdict_batch_size' );
        delete_option( 'onsite_link_verdict_time_budget' );
        delete_option( 'onsite_link_verdict_version' );
        delete_option( 'onsite_link_verdict_ops_installed' );
        // Ponto onde o varrimento completo ficou, se estava a meio de um.
        delete_option( Scanner::CURSOR_OPTION );
        // Origens cujo conteúdo passou do teto de links.
        delete_option( Scanner::TRUNCATED_OPTION );
        delete_option( Scanner::SKIPPED_LONG_OPTION );
        // O fecho contra passagens simultâneas do cron.
        delete_option( Cron::LOCK_OPTION );
        delete_option( Cron::LOCK_SCAN_OPTION );
        // As regras de exclusão da versão Pro. Pelo mesmo motivo, pelo nome.
        delete_option( 'onsite_link_verdict_exclusions' );
    }
}
