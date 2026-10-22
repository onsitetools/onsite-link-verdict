<?php

/**
 * Plugin Name:       Onsite Link Verdict — Broken Link Checker
 * Plugin URI:        https://onsitetools.dev/onsite-link-verdict/
 * Description:       Finds broken links on your site. The checking runs on your own server — no cloud service, no account, no signup, no credits.
 * Version:           0.9.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Onsite Tools
 * Author URI:        https://profiles.wordpress.org/onsitetools/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       onsite-link-verdict
 * Domain Path:       /languages
 *
 * @package OnsiteLinkVerdict
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Pendura a limpeza da desinstalação na acção do Freemius.
 *
 * Existe como função à parte, e recebe a instância em vez de a ir buscar, por
 * uma razão só: assim há maneira de PROVAR que o registo acontece. A alternativa
 * — chamar `onsite_link_verdict_fs()->add_action(...)` no meio deste ficheiro — é código que
 * ninguém consegue exercitar, e hoje mesmo apanhámos um aviso escrito que nunca
 * era chamado por estar pendurado no sítio errado. Uma vez chega.
 *
 * 🔴 **A guarda do `function_exists()` nao e estilo, e o que impede um site de ir
 * abaixo.** Quando alguem compra, fica com a versao gratuita e a Pro instaladas
 * em pastas diferentes, e o WordPress carrega este ficheiro duas vezes. Sem a
 * guarda, o PHP para ali com *"Cannot redeclare function"* — e o cliente nao ve
 * um aviso, ve o site inteiro em baixo, admin e frente. Aconteceu no ensaio de
 * 20/08/2026, no minuto seguinte a esta funcao ser escrita. O snippet do
 * Freemius, logo a seguir, protege-se da mesma maneira e foi de la que veio a
 * resposta. **Qualquer funcao nova neste ficheiro leva a mesma guarda** — ha um
 * teste que recusa o contrario.
 *
 * @param object $freemius Instância do SDK.
 * @return bool Se ficou registado.
 */
if ( !function_exists( 'onsite_link_verdict_registar_desinstalacao' ) ) {
    function onsite_link_verdict_registar_desinstalacao(  $freemius  ) {
        if ( !is_object( $freemius ) || !method_exists( $freemius, 'add_action' ) ) {
            return false;
        }
        $freemius->add_action( 'after_uninstall', array('\\OnsiteLinkVerdict\\Uninstaller', 'run') );
        return true;
    }

}
/*
 * ── O Freemius, que é quem vende a versão Pro ─────────────────────────────
 *
 * Este bloco vem da página "SDK Integration" do painel do Freemius e cola-se
 * TAL COMO ELES O GERAM. Não vale a pena arrumá-lo nem reordená-lo: o
 * preprocessador deles lê-o na publicação, e é ele que gera a versão gratuita
 * a partir desta.
 *
 * Duas notas que não são óbvias:
 *
 * 1. O `wp_org_gatekeeper` é uma trave de segurança DELES contra publicar por
 *    engano a versão paga no WordPress.org. Não é um segredo no sentido normal
 *    — vai dentro do ZIP que cada cliente recebe — mas **não pode aparecer no
 *    pacote que sobe ao WordPress.org**. Por isso o que se envia ao repositório
 *    é a versão gratuita GERADA pelo Freemius, e não este ficheiro à mão.
 *
 * 2. A chave `sk_...` que o painel também mostra é de outra natureza: essa é
 *    mesmo secreta, vive só no `wp-config.php` do site de ensaio e **nunca**
 *    entra num ficheiro deste repositório.
 *
 * A constante da trava existe para os testes: a suite corre sem rede e não
 * pode ficar à espera da API do Freemius. Ver a nota em `class-pro.php` sobre
 * o que isso deixa POR cobrir.
 */
if ( !defined( 'ONSITE_LINK_VERDICT_SKIP_FREEMIUS' ) && !function_exists( 'onsite_link_verdict_fs' ) ) {
    // Create a helper function for easy SDK access.
    function onsite_link_verdict_fs() {
        global $onsite_link_verdict_fs;
        if ( !isset( $onsite_link_verdict_fs ) ) {
            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';
            $onsite_link_verdict_fs = fs_dynamic_init( array(
                'id'               => '37295',
                'slug'             => 'onsite-link-verdict',
                'premium_slug'     => 'onsite-link-verdict-pro',
                'type'             => 'plugin',
                'public_key'       => 'pk_ba127dc74cdb619e11163ee854911',
                'is_premium'       => false,
                'premium_suffix'   => '(Pro)',
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'is_org_compliant' => true,
                'menu'             => array(
                    'slug'    => 'onsite-link-verdict',
                    'support' => false,
                    'parent'  => array(
                        'slug' => 'tools.php',
                    ),
                ),
                'is_live'          => true,
            ) );
            // A tabela de preços dentro do wp-admin ("Change Plan") abre na moeda
            // que este filtro devolver. O SDK assume USD, e os planos só têm preço
            // em EUR — sem isto, quem já é cliente e quer mais sites via uma tabela
            // VAZIA, sem erro nenhum (medido a 29/08/2026). O checkout alojado no
            // Freemius escolhe a moeda pelo país do comprador por conta própria;
            // isto só manda no ecrã de dentro do WordPress.
            $onsite_link_verdict_fs->add_filter( 'default_currency', static function () {
                return 'eur';
            } );
        }
        return $onsite_link_verdict_fs;
    }

    // Init Freemius.
    onsite_link_verdict_fs();
    // Signal that SDK was initiated.
    do_action( 'onsite_link_verdict_fs_loaded' );
    // A limpeza da desinstalação pendura-se no SDK. Ver `class-uninstaller.php`
    // para a razão — em resumo, só há espaço para um callback e ele é do Freemius.
    require_once __DIR__ . '/includes/autoload.php';
    onsite_link_verdict_registar_desinstalacao( onsite_link_verdict_fs() );
}
if ( !defined( 'ONSITE_LINK_VERDICT_VERSION' ) ) {
    define( 'ONSITE_LINK_VERDICT_VERSION', '0.9.0' );
    define( 'ONSITE_LINK_VERDICT_FILE', __FILE__ );
    define( 'ONSITE_LINK_VERDICT_DIR', plugin_dir_path( __FILE__ ) );
}
// Autoloader próprio — vive em includes/autoload.php para poder ser exercitado
// por testes sem arrastar consigo o registo dos hooks deste ficheiro.
require_once __DIR__ . '/includes/autoload.php';
/*
 * A tradução empacotada em /languages carrega sozinha — sem `load_plugin_textdomain()`.
 *
 * Essa chamada foi retirada a 19/08/2026 porque a ferramenta que o WordPress.org
 * corre na revisão (o Plugin Check) a marca como desencorajada desde o WP 4.6.
 * O comentário que aqui estava dizia que sem ela o `.mo` da pasta do plugin
 * nunca era encontrado — **testou-se e já não é verdade**: com o locale em
 * pt_PT, o `__( 'Broken', 'onsite-link-verdict' )` devolveu "Partidos" sem chamada
 * nenhuma. Quem trata disto é o registo de domínios de texto do WordPress, que
 * lê o cabeçalho `Domain Path`.
 *
 * É por isso que o `Requires at least` subiu para **6.1**: é a versão em que
 * esse registo passou a existir. Abaixo dela, a tradução empacotada não
 * carregaria — e um requisito honesto vale mais do que um mínimo optimista.
 */
/*
 * Os intervalos de que os niveis de velocidade precisam.
 *
 * Fica AQUI, e nao dentro de um hook do painel, porque tem de valer tambem nos
 * pedidos de cron — e' la que o WordPress vai perguntar qual e' o intervalo de um
 * evento. Sem isto, o agendamento de 15 ou 5 minutos e' recusado em silencio.
 */
add_filter( 'cron_schedules', array('\\OnsiteLinkVerdict\\Cron', 'register_schedules') );
// phpcs:ignore WordPress.WP.CronInterval -- os intervalos curtos sao uma escolha do utilizador, com aviso no painel.
register_activation_hook( ONSITE_LINK_VERDICT_FILE, function () {
    $store = new \OnsiteLinkVerdict\Store();
    $store->install();
    // Sem esta opção gravada, o caminho de atualização do admin_init não tem
    // com que comparar e o dbDelta() de uma versão futura nunca correria.
    update_option( \OnsiteLinkVerdict\Store::VERSION_OPTION, ONSITE_LINK_VERDICT_VERSION, false );
    $cron = new \OnsiteLinkVerdict\Cron();
    $cron->schedule();
    // A data de instalacao, que e' o relogio do convite a avaliar. Usa
    // add_option, por isso uma reactivacao nao reinicia a contagem.
    \OnsiteLinkVerdict\Review_Prompt::registar_instalacao();
} );
/**
 * Rede de segurança do painel — duas coisas que a ativação faz uma vez e depois
 * mais ninguém garante.
 *
 * 1. O agendamento. O Cron::schedule() só corria no register_activation_hook:
 *    bastava um plugin de limpeza de cron, uma migração de site ou uma restauração
 *    de base de dados e o evento desaparecia para sempre — nem uma atualização do
 *    plugin o repunha, porque só a REATIVAÇÃO dispara o hook. As verificações
 *    paravam em silêncio, que é a pior maneira de parar.
 * 2. O esquema. Ver Store::maybe_upgrade().
 *
 * As duas verificações custam uma leitura de opção cada, e só no painel.
 */
add_action( 'admin_init', function () {
    ( new \OnsiteLinkVerdict\Cron() )->schedule();
    ( new \OnsiteLinkVerdict\Store() )->maybe_upgrade( ONSITE_LINK_VERDICT_VERSION );
} );
register_deactivation_hook( ONSITE_LINK_VERDICT_FILE, function () {
    $cron = new \OnsiteLinkVerdict\Cron();
    $cron->unschedule();
} );
add_action( \OnsiteLinkVerdict\Cron::HOOK, function () {
    $cron = new \OnsiteLinkVerdict\Cron();
    $cron->run_batch();
} );
/**
 * Evento único acionado pelo botão "Iniciar varrimento" do painel — coloca o
 * site inteiro em fila. A verificação propriamente dita corre depois, aos
 * poucos, pelo Cron::HOOK normal — nunca aqui.
 */
add_action( \OnsiteLinkVerdict\Cron::HOOK_QUEUE_ALL, function () {
    ( new \OnsiteLinkVerdict\Scanner() )->queue_all();
} );
/*
 * ── A versão paga liga-se aqui, ou não existe de todo ────────────────────────
 *
 * A regra 5 do repositório do WordPress.org não deixa o pacote gratuito levar o
 * código da funcionalidade paga. Por isso as classes da Pro ficam fora desse
 * pacote, e este ficheiro — que vai nos dois — limita-se a perguntar se elas cá
 * estão.
 *
 * O que estava aqui antes (o descarregar do CSV e o registo do ecrã de
 * substituir) mudou-se para o `Pro_Boot`, que só existe no pacote pago. Ver
 * `class-pro-boot__premium_only.php`.
 *
 * ⚠️ O `class_exists()` faz o autoloader procurar o ficheiro. No pacote gratuito
 * não o encontra e responde "não" — que é a resposta certa, e a única que não
 * deita o site abaixo.
 */
if ( class_exists( '\\OnsiteLinkVerdict\\Pro_Boot' ) ) {
    \OnsiteLinkVerdict\Pro_Boot::register();
} else {
    add_action( 'admin_menu', function () {
        // Sem o código da Pro, quem atende a acção "Replace everywhere" de
        // cada linha é o convite. Mesmo endereço, outra página.
        ( new \OnsiteLinkVerdict\Upsell_Page() )->register();
    } );
}
add_action( 'admin_menu', function () {
    $pagina = new \OnsiteLinkVerdict\Admin_Page();
    $pagina->register();
} );
/*
 * As abilities para assistentes de IA (WordPress 6.9+). Abaixo dessa versão
 * estes dois hooks nunca disparam. Ver `includes/class-abilities.php`.
 */
add_action( 'wp_abilities_api_categories_init', array('\\OnsiteLinkVerdict\\Abilities', 'register_category') );
add_action( 'wp_abilities_api_init', array('\\OnsiteLinkVerdict\\Abilities', 'register') );
/**
 * Quando um artigo é publicado ou atualizado, o varrimento é AGENDADO em
 * segundo plano — nunca corre em linha com a gravação.
 *
 * O Scanner chama apply_filters( 'the_content', ... ), e o WordPress tem aí
 * pendurado, por omissão, o WP_Embed::autoembed — que pode fazer pedidos
 * HTTP de oEmbed para cada endereço ainda sem cache. Num artigo com vários
 * embeds isso soma-se ao tempo do próprio pedido de gravação, e em
 * alojamento partilhado (max_execution_time curto) trava o "Publicar". É a
 * sensação de site avariado que este produto existe para não causar.
 */
add_action(
    'save_post',
    function ( $post_id ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        // Se já há um varrimento agendado para este artigo, não se agenda outro —
        // senão, gravar cinco vezes seguidas enfileirava cinco varrimentos iguais.
        if ( !wp_next_scheduled( \OnsiteLinkVerdict\Cron::HOOK_SCAN_POST, array($post_id) ) ) {
            wp_schedule_single_event( time() + 30, \OnsiteLinkVerdict\Cron::HOOK_SCAN_POST, array($post_id) );
        }
    },
    10,
    1
);
add_action(
    \OnsiteLinkVerdict\Cron::HOOK_SCAN_POST,
    function ( $post_id ) {
        $scanner = new \OnsiteLinkVerdict\Scanner();
        $scanner->scan_post( $post_id );
    },
    10,
    1
);
/**
 * Quando um comentário é criado ou aprovado, o varrimento é AGENDADO em
 * segundo plano — nunca corre em linha com a inserção ou aprovação.
 *
 * Percorre dois hooks: comment_post (comentário novo) e
 * transition_comment_status (aprovação posterior), porque um comentário pode
 * chegar já aprovado ou ser aprovado depois.
 *
 * Os dois IDs de comentário são sempre convertidos para inteiro antes de
 * agendar. O WordPress devolve o ID como inteiro em 'comment_post' (vem de
 * wp_insert_comment()) mas como string em 'transition_comment_status' (vem
 * de uma linha lida da base de dados) — sem esta conversão, os dois hooks
 * agendavam o MESMO comentário com argumentos de tipos diferentes, e a
 * verificação `wp_next_scheduled()` que evita duplicados deixava de encontrar
 * o agendamento já feito pelo outro hook.
 */
add_action(
    'comment_post',
    function ( $comment_id, $comment_approved ) {
        /*
         * 'comment_post' passa TRÊS argumentos: ($comment_id, $comment_approved,
         * $commentdata) — não um objeto de comentário. Sem indicar 10, 2 aqui,
         * o WordPress só entrega o primeiro argumento ao callback e
         * $comment_approved chega a null, que é diferente de '1' e faz o
         * varrimento nunca ser agendado. Foi essa a causa real da falha
         * anterior — não a falta de recarregar o comentário da base de dados.
         */
        $comment_id = (int) $comment_id;
        if ( '1' !== (string) $comment_approved ) {
            return;
        }
        if ( !wp_next_scheduled( \OnsiteLinkVerdict\Cron::HOOK_SCAN_COMMENT, array($comment_id) ) ) {
            wp_schedule_single_event( time() + 30, \OnsiteLinkVerdict\Cron::HOOK_SCAN_COMMENT, array($comment_id) );
        }
    },
    10,
    2
);
add_action(
    'transition_comment_status',
    function ( $new_status, $old_status, $comment_object ) {
        $comment_id = (int) $comment_object->comment_ID;
        // Deixar de estar aprovado (lixo, spam, por moderar) é deixar de ser
        // conteúdo publicado: os links saem da lista e o varrimento pendente,
        // se o havia, é cancelado.
        if ( 'approved' !== $new_status ) {
            wp_clear_scheduled_hook( \OnsiteLinkVerdict\Cron::HOOK_SCAN_COMMENT, array($comment_id) );
            ( new \OnsiteLinkVerdict\Store() )->delete_by_source( 'comment', $comment_id );
            return;
        }
        if ( !wp_next_scheduled( \OnsiteLinkVerdict\Cron::HOOK_SCAN_COMMENT, array($comment_id) ) ) {
            wp_schedule_single_event( time() + 30, \OnsiteLinkVerdict\Cron::HOOK_SCAN_COMMENT, array($comment_id) );
        }
    },
    10,
    3
);
/**
 * Eliminação de conteúdo — as linhas saem da tabela.
 *
 * Não havia um único DELETE no plugin, e isso dava três problemas todos visíveis
 * ao cliente: um artigo apagado deixava linhas órfãs (que a coluna "Where it is"
 * desenhava como um link vazio), o painel acusava links de conteúdo que já não
 * existe, e a tabela crescia sem limite, para sempre.
 *
 * Um artigo no lixo conta como eliminado para este efeito: deixou de ser conteúdo
 * publicado, logo os seus links não pertencem à lista. Se voltar do lixo e for
 * republicado, o save_post volta a agendar o varrimento e os links regressam.
 *
 * Nenhum destes gatilhos agenda seja o que for — só cancelam e apagam —, portanto
 * não acrescentam nada por limpar na desinstalação.
 */
add_action(
    'deleted_post',
    function ( $post_id ) {
        wp_clear_scheduled_hook( \OnsiteLinkVerdict\Cron::HOOK_SCAN_POST, array((int) $post_id) );
        ( new \OnsiteLinkVerdict\Store() )->delete_by_source( 'post', (int) $post_id );
    },
    10,
    1
);
add_action(
    'trashed_post',
    function ( $post_id ) {
        wp_clear_scheduled_hook( \OnsiteLinkVerdict\Cron::HOOK_SCAN_POST, array((int) $post_id) );
        ( new \OnsiteLinkVerdict\Store() )->delete_by_source( 'post', (int) $post_id );
    },
    10,
    1
);
add_action(
    'deleted_comment',
    function ( $comment_id ) {
        wp_clear_scheduled_hook( \OnsiteLinkVerdict\Cron::HOOK_SCAN_COMMENT, array((int) $comment_id) );
        ( new \OnsiteLinkVerdict\Store() )->delete_by_source( 'comment', (int) $comment_id );
    },
    10,
    1
);
add_action(
    \OnsiteLinkVerdict\Cron::HOOK_SCAN_COMMENT,
    function ( $comment_id ) {
        $scanner = new \OnsiteLinkVerdict\Scanner();
        $scanner->scan_comment( $comment_id );
    },
    10,
    1
);