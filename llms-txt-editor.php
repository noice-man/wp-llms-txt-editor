<?php
/**
 * Plugin Name: LLMS.txt Editor
 * Plugin URI: https://ворд-пресс.рф
 * Description: Создаёт и редактирует llms.txt в админке WordPress. Проверяет кодировку и предлагает безопасную альтернативу настройке сервера.
 * Version: 1.2.0
 * Author: Valery Molodtsov
 * Author URI: https://ворд-пресс.рф
 * License: GPL-2.0-or-later
 * Text Domain: llms-txt-editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Публичный корень сайта (он может отличаться от ABSPATH). */
function vm_llms_file_path() {
    if ( ! function_exists( 'get_home_path' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    return trailingslashit( get_home_path() ) . 'llms.txt';
}

function vm_llms_virtual_mode() {
    return 'virtual' === get_option( 'vm_llms_mode', 'file' );
}

function vm_llms_public_url( $query = array() ) {
    $url = home_url( '/llms.txt' );
    return $query ? add_query_arg( $query, $url ) : $url;
}

/** Один и тот же редактор обслуживает файл или сохранённый текст виртуального ответа. */
function vm_llms_current_content() {
    if ( vm_llms_virtual_mode() ) {
        $text = get_option( 'vm_llms_virtual_content', '' );
        return is_string( $text ) ? $text : '';
    }
    $path = vm_llms_file_path();
    if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
        return new WP_Error( 'llms_read_error', 'Файл llms.txt не найден или недоступен для чтения.' );
    }
    $text = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    return false === $text ? new WP_Error( 'llms_read_error', 'Не удалось прочитать llms.txt.' ) : $text;
}

function vm_llms_validate_content( $text ) {
    if ( ! is_string( $text ) ) {
        return new WP_Error( 'llms_not_text', 'Содержимое должно быть текстом.' );
    }
    if ( strlen( $text ) > 1024 * 1024 ) {
        return new WP_Error( 'llms_too_large', 'Размер файла не должен превышать 1 МБ.' );
    }
    if ( false !== strpos( $text, "\0" ) || ! seems_utf8( $text ) ) {
        return new WP_Error( 'llms_invalid_encoding', 'Текст должен быть корректным UTF-8 и не содержать нулевых байтов.' );
    }
    return true;
}

/** Физический файл по умолчанию: никаких изменений существующего файла при активации. */
function vm_llms_write_file( $content ) {
    $valid = vm_llms_validate_content( $content );
    if ( is_wp_error( $valid ) ) {
        return $valid;
    }
    $path = vm_llms_file_path();
    $root = dirname( $path );
    if ( ! is_dir( $root ) ) {
        return new WP_Error( 'llms_missing_root', 'Публичный корень сайта не найден.' );
    }
    if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
        return new WP_Error( 'llms_invalid_file', 'По пути llms.txt находится не обычный файл. Запись отменена.' );
    }
    if ( file_exists( $path ) && ! is_writable( $path ) ) {
        return new WP_Error( 'llms_file_readonly', 'Файл llms.txt недоступен для записи.' );
    }
    if ( ! file_exists( $path ) && ! is_writable( $root ) ) {
        return new WP_Error( 'llms_root_readonly', 'Корневая папка сайта недоступна для записи.' );
    }
    $written = @file_put_contents( $path, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    if ( false === $written || $written !== strlen( $content ) ) {
        return new WP_Error( 'llms_write_failed', 'Не удалось записать llms.txt. Проверьте права и свободное место.' );
    }
    clearstatcache( true, $path );
    return true;
}

function vm_llms_store_virtual( $content ) {
    $valid = vm_llms_validate_content( $content );
    if ( is_wp_error( $valid ) ) {
        return $valid;
    }
    update_option( 'vm_llms_virtual_content', $content, false );
    if ( get_option( 'vm_llms_virtual_content', null ) !== $content ) {
        return new WP_Error( 'llms_option_failed', 'Не удалось записать содержимое llms.txt в базу данных.' );
    }
    return true;
}

function vm_llms_save_content( $content ) {
    return vm_llms_virtual_mode() ? vm_llms_store_virtual( $content ) : vm_llms_write_file( $content );
}

function vm_llms_ensure_file() {
    if ( vm_llms_virtual_mode() ) {
        return true; // Никогда не создаём статический файл поверх виртуального адреса.
    }
    $path = vm_llms_file_path();
    if ( file_exists( $path ) || is_link( $path ) ) {
        return true;
    }
    $name = trim( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
    $description = trim( wp_strip_all_tags( get_bloginfo( 'description' ) ) );
    $text = '# ' . ( '' !== $name ? $name : 'Название сайта' ) . "\n";
    if ( '' !== $description ) {
        $text .= "\n> " . $description . "\n";
    }
    $text .= "\n- [Главная](" . home_url( '/' ) . "): Главная страница сайта.\n";
    return vm_llms_write_file( $text );
}

/** Apache: только физический llms.txt; Nginx не использует .htaccess. */
function vm_llms_ensure_utf8_header() {
    if ( vm_llms_virtual_mode() ) {
        return true;
    }
    $htaccess = dirname( vm_llms_file_path() ) . '/.htaccess';
    if ( is_link( $htaccess ) || ( file_exists( $htaccess ) && ! is_file( $htaccess ) ) ) {
        return new WP_Error( 'llms_htaccess_invalid', 'Не удалось проверить .htaccess: неподдерживаемый тип файла.' );
    }
    $rules = array(
        '<Files "llms.txt">',
        '    <IfModule mod_mime.c>',
        '        AddType text/plain .txt',
        '        AddCharset UTF-8 .txt',
        '    </IfModule>',
        '    <IfModule mod_headers.c>',
        '        Header set Content-Type "text/plain; charset=UTF-8"',
        '    </IfModule>',
        '</Files>',
    );
    if ( is_file( $htaccess ) && is_readable( $htaccess ) ) {
        $existing = @file_get_contents( $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false !== $existing ) {
            $begin = strpos( $existing, '# BEGIN LLMS TXT EDITOR' );
            $end = strpos( $existing, '# END LLMS TXT EDITOR' );
            if ( false !== $begin && false !== $end && $end > $begin ) {
                $block = substr( $existing, $begin, $end - $begin );
                if ( false !== strpos( $block, 'AddCharset UTF-8 .txt' ) && false !== strpos( $block, 'Header set Content-Type "text/plain; charset=UTF-8"' ) ) {
                    return true;
                }
            }
        }
    }
    if ( file_exists( $htaccess ) && ! is_writable( $htaccess ) ) {
        return new WP_Error( 'llms_htaccess_readonly', 'Нет прав записи в .htaccess. Для Apache настройте заголовок вручную.' );
    }
    if ( ! file_exists( $htaccess ) && ! is_writable( dirname( $htaccess ) ) ) {
        return new WP_Error( 'llms_htaccess_root_readonly', 'Нет прав создать .htaccess.' );
    }
    if ( ! function_exists( 'insert_with_markers' ) ) {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }
    return insert_with_markers( $htaccess, 'LLMS TXT EDITOR', $rules ) ? true : new WP_Error( 'llms_htaccess_failed', 'Не удалось обновить .htaccess.' );
}

/**
 * WordPress отвечает за виртуальный llms.txt только после отдельного согласия.
 * Хук parse_request не требует изменения rewrite-правил WordPress, но Nginx
 * обязательно должен передавать несуществующие TXT-файлы в index.php.
 */
add_action( 'parse_request', 'vm_llms_public_request', 0 );
function vm_llms_public_request( $wp ) {
    if ( empty( $_SERVER['REQUEST_URI'] ) ) {
        return;
    }
    $path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
    $target = wp_parse_url( vm_llms_public_url(), PHP_URL_PATH );
    if ( ! is_string( $path ) || ! is_string( $target ) ) {
        return;
    }

    // Эфемерный тест того, передаёт ли Nginx отсутствующий .txt в WordPress.
    $prefix = substr( $target, 0, -strlen( 'llms.txt' ) );
    if ( preg_match( '~^' . preg_quote( $prefix, '~' ) . '__llms-editor-probe-([a-f0-9]{32})\.txt$~D', $path, $matches ) ) {
        $token = $matches[1];
        if ( '1' !== get_transient( 'vm_llms_probe_' . $token ) ) {
            return;
        }
        vm_llms_emit_plain( 'LLMS-EDITOR-PROBE-' . $token, 'probe' );
    }

    if ( vm_llms_virtual_mode() && $path === $target ) {
        $text = get_option( 'vm_llms_virtual_content', '' );
        vm_llms_emit_plain( is_string( $text ) ? $text : '', 'virtual' );
    }
}

function vm_llms_emit_plain( $text, $mode ) {
    if ( ! in_array( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET', array( 'GET', 'HEAD' ), true ) ) {
        status_header( 405 );
        header( 'Allow: GET, HEAD' );
        exit;
    }
    status_header( 200 );
    header( 'Content-Type: text/plain; charset=UTF-8', true );
    header( 'X-Content-Type-Options: nosniff', true );
    header( 'X-LLMS-Editor-Mode: ' . $mode, true );
    header( 'Cache-Control: no-cache, no-store, must-revalidate', true );
    if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'HEAD' !== $_SERVER['REQUEST_METHOD'] ) {
        echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain UTF-8 file, not HTML.
    }
    exit;
}

/** Заголовок должен указывать и правильный тип, и правильную кодировку. */
function vm_llms_valid_content_type( $type ) {
    return 0 === stripos( trim( (string) $type ), 'text/plain' ) &&
        (bool) preg_match( '/(?:^|;)\s*charset\s*=\s*["\']?utf-8["\']?\s*(?:;|$)/i', (string) $type );
}

/** Проверка внешнего HTTP-ответа, а не содержимого PHP/БД. */
function vm_llms_http_check( $refresh = false ) {
    $cache_key = 'vm_llms_http_check';
    if ( ! $refresh ) {
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
    }
    $url = vm_llms_public_url( array( '_llms_check' => wp_generate_password( 16, false, false ) ) );
    $response = wp_remote_head( $url, array( 'timeout' => 8, 'redirection' => 0 ) );
    if ( is_wp_error( $response ) ) {
        $result = array( 'status' => 'unknown', 'detail' => $response->get_error_message() );
    } else {
        $http = (int) wp_remote_retrieve_response_code( $response );
        $type = (string) wp_remote_retrieve_header( $response, 'content-type' );
        if ( 200 !== $http ) {
            $result = array( 'status' => 'unknown', 'detail' => 'HTTP ' . $http . ', заголовок Content-Type: ' . ( $type ?: 'отсутствует' ) );
        } elseif ( vm_llms_valid_content_type( $type ) ) {
            $result = array( 'status' => 'ok', 'detail' => $type );
        } else {
            $result = array( 'status' => 'missing', 'detail' => $type ?: 'Content-Type отсутствует' );
        }
    }
    set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
    return $result;
}

/** Испытываем именно маршрут .txt до удаления физического файла. */
function vm_llms_probe_wordpress_route() {
    $token = bin2hex( random_bytes( 16 ) );
    $key = 'vm_llms_probe_' . $token;
    set_transient( $key, '1', 90 );
    $url = home_url( '/__llms-editor-probe-' . $token . '.txt' );
    $response = wp_remote_get( add_query_arg( array( '_llms_probe' => $token ), $url ), array( 'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 256 ) );
    delete_transient( $key );
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'llms_probe_http', 'Не удалось выполнить проверку URL с сервера: ' . $response->get_error_message() );
    }
    if ( 200 !== wp_remote_retrieve_response_code( $response ) ||
        'LLMS-EDITOR-PROBE-' . $token !== wp_remote_retrieve_body( $response ) ||
        'probe' !== wp_remote_retrieve_header( $response, 'x-llms-editor-mode' ) ) {
        return new WP_Error( 'llms_probe_failed', 'Сервер не передаёт отсутствующие TXT-адреса в WordPress. Автоматическое решение недоступно: требуется настройка Nginx или хостинга.' );
    }
    return true;
}

/** Переключаемся только с резервной копией и проверкой настоящего URL. */
function vm_llms_enable_virtual_mode() {
    if ( vm_llms_virtual_mode() ) {
        return true;
    }
    $probe = vm_llms_probe_wordpress_route();
    if ( is_wp_error( $probe ) ) {
        return $probe;
    }
    $content = vm_llms_current_content();
    if ( is_wp_error( $content ) ) {
        return $content;
    }
    $saved = vm_llms_store_virtual( $content );
    if ( is_wp_error( $saved ) ) {
        return $saved;
    }
    $path = vm_llms_file_path();
    if ( is_link( $path ) || ! is_file( $path ) || ! is_writable( dirname( $path ) ) ) {
        return new WP_Error( 'llms_backup_unavailable', 'Не удалось подготовить переключение: проверьте права записи в корне сайта и тип файла.' );
    }
    $backup = dirname( $path ) . '/.llms-txt-editor-' . bin2hex( random_bytes( 8 ) ) . '.backup';
    if ( ! @rename( $path, $backup ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        return new WP_Error( 'llms_rename_failed', 'Не удалось временно убрать физический llms.txt. Изменения отменены.' );
    }
    clearstatcache( true, $path );
    update_option( 'vm_llms_mode', 'virtual', false );
    if ( ! vm_llms_virtual_mode() ) {
        @rename( $backup, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        return new WP_Error( 'llms_option_failed', 'Не удалось активировать виртуальный режим. Файл восстановлен.' );
    }
    $check = wp_remote_get( vm_llms_public_url( array( '_llms_verify' => wp_generate_password( 24, false, false ) ) ), array( 'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 16 ) );
    $verified = ! is_wp_error( $check ) &&
        200 === wp_remote_retrieve_response_code( $check ) &&
        'virtual' === wp_remote_retrieve_header( $check, 'x-llms-editor-mode' ) &&
        vm_llms_valid_content_type( wp_remote_retrieve_header( $check, 'content-type' ) );
    if ( ! $verified ) {
        update_option( 'vm_llms_mode', 'file', false );
        if ( ! @rename( $backup, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            // Экстренная попытка восстановить файл из сохранённой копии.
            $restored = vm_llms_write_file( $content );
            if ( is_wp_error( $restored ) ) {
                // Не теряем последний экземпляр: резервную копию не удаляем.
                return new WP_Error( 'llms_critical_restore', 'Проверка виртуального URL не прошла, а восстановление файла не удалось. Резервная копия: ' . $backup );
            }
            @unlink( $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        }
        return new WP_Error( 'llms_verify_failed', 'Виртуальный llms.txt не прошёл проверку через ваш веб-сервер. Физический файл восстановлен, режим не изменён.' );
    }
    // Виртуальная версия проверена и сохранена в базе; временная копия больше не нужна.
    @unlink( $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
    delete_transient( 'vm_llms_http_check' );
    return true;
}

/** Возврат к исходной схеме, в том числе перед деактивацией плагина. */
function vm_llms_disable_virtual_mode() {
    if ( ! vm_llms_virtual_mode() ) {
        return true;
    }
    $path = vm_llms_file_path();
    if ( file_exists( $path ) || is_link( $path ) ) {
        return new WP_Error( 'llms_physical_conflict', 'Физический llms.txt уже существует. Проверьте его вручную: автоматическая замена отменена.' );
    }
    $content = vm_llms_current_content();
    $saved = vm_llms_write_file( $content );
    if ( is_wp_error( $saved ) ) {
        return $saved;
    }
    update_option( 'vm_llms_mode', 'file', false );
    delete_transient( 'vm_llms_http_check' );
    return true;
}

function vm_llms_activate() {
    if ( ! vm_llms_virtual_mode() ) {
        $created = vm_llms_ensure_file();
        if ( ! is_wp_error( $created ) ) {
            vm_llms_ensure_utf8_header();
        }
    }
}
register_activation_hook( __FILE__, 'vm_llms_activate' );

function vm_llms_deactivate() {
    if ( vm_llms_virtual_mode() ) {
        $result = vm_llms_disable_virtual_mode();
        if ( is_wp_error( $result ) ) {
            // Не отключаем маршрутизацию WordPress, если физический файл не восстановлен.
            wp_die( esc_html( 'Нельзя деактивировать плагин, пока не восстановлен llms.txt: ' . $result->get_error_message() ), 'LLMS.txt Editor', array( 'response' => 409 ) );
        }
    }
}
register_deactivation_hook( __FILE__, 'vm_llms_deactivate' );

add_action( 'admin_menu', function () {
    add_menu_page( 'Редактор llms.txt', 'LLMS.txt', 'manage_options', 'vm-llms-editor', 'vm_llms_render_editor', 'dashicons-media-text', 81 );
} );

function vm_llms_render_editor() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Недостаточно прав для редактирования llms.txt.' );
    }
    $error = '';
    $success = '';
    $draft = null;

    if ( isset( $_POST['vm_llms_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        check_admin_referer( 'vm_llms_action', 'vm_llms_nonce' );
        $action = sanitize_key( wp_unslash( $_POST['vm_llms_action'] ) );
        if ( 'save' === $action ) {
            if ( ! isset( $_POST['vm_llms_content'] ) || ! is_string( $_POST['vm_llms_content'] ) ) {
                $error = 'Текст не получен.';
            } else {
                $draft = str_replace( array( "\r\n", "\r" ), "\n", wp_unslash( $_POST['vm_llms_content'] ) );
                $result = vm_llms_save_content( $draft );
                if ( is_wp_error( $result ) ) {
                    $error = $result->get_error_message();
                } else {
                    $success = 'Файл llms.txt сохранён.';
                    delete_transient( 'vm_llms_http_check' );
                }
            }
        } elseif ( 'enable_virtual' === $action ) {
            $result = vm_llms_enable_virtual_mode();
            if ( is_wp_error( $result ) ) {
                $error = $result->get_error_message();
            } else {
                $success = 'Включена выдача llms.txt через WordPress. UTF-8 проверен.';
            }
        } elseif ( 'disable_virtual' === $action ) {
            $result = vm_llms_disable_virtual_mode();
            if ( is_wp_error( $result ) ) {
                $error = $result->get_error_message();
            } else {
                $success = 'Физический файл llms.txt восстановлен.';
            }
        } elseif ( 'check' === $action ) {
            delete_transient( 'vm_llms_http_check' );
        }
    }

    if ( ! vm_llms_virtual_mode() ) {
        $created = vm_llms_ensure_file();
        if ( is_wp_error( $created ) && ! $error ) {
            $error = $created->get_error_message();
        }
        $header = vm_llms_ensure_utf8_header();
        if ( is_wp_error( $header ) && ! $error ) {
            $error = $header->get_error_message();
        }
    }
    if ( null === $draft ) {
        $content = vm_llms_current_content();
        if ( is_wp_error( $content ) ) {
            $content = '';
            if ( ! $error ) {
                $error = 'Не удалось прочитать llms.txt.';
            }
        }
    } else {
        $content = $draft;
    }
    $check = vm_llms_http_check();
    $url = vm_llms_public_url();
    // Новая ссылка после каждого сохранения обходит семидневный кэш статики.
    $preview_url = vm_llms_public_url( array( '_llms_preview' => wp_generate_password( 12, false, false ) ) );
    ?>
    <div class="wrap">
        <h1>Редактор llms.txt</h1>
        <p>Редактируйте содержимое <code>/llms.txt</code> в UTF-8. Поддерживается Markdown.</p>
        <p><strong>Адрес файла:</strong> <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $url ); ?></a></p>
        <?php if ( $success ) : ?>
            <div class="notice notice-success is-dismissible"><p><strong><?php echo esc_html( $success ); ?></strong> <a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer">Открыть llms.txt ↗</a></p></div>
        <?php endif; ?>
        <?php if ( $error ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=vm-llms-editor' ) ); ?>">
            <?php wp_nonce_field( 'vm_llms_action', 'vm_llms_nonce' ); ?>
            <input type="hidden" name="vm_llms_action" value="save">
            <label for="vm_llms_content" class="screen-reader-text">Содержимое llms.txt</label>
            <textarea id="vm_llms_content" name="vm_llms_content" class="large-text code" rows="24" style="max-width:1100px" spellcheck="false"><?php echo esc_textarea( $content ); ?></textarea>
            <p class="submit" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
                <button type="submit" class="button button-primary">Сохранить llms.txt</button>
                <a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer">Открыть llms.txt ↗</a>
            </p>
        </form>
        <div style="max-width:1100px;margin-top:20px;border:1px solid #c3c4c7;background:#fff;padding:16px 20px">
            <h2 style="margin-top:0">Кодировка и способ выдачи</h2>
            <p><strong>Режим:</strong> <?php echo vm_llms_virtual_mode() ? 'Через WordPress (без физического файла)' : 'Физический файл /llms.txt'; ?></p>
            <p><strong>HTTP-проверка:</strong>
                <?php if ( 'ok' === $check['status'] ) : ?>
                    <span style="color:#008a20">✓ Сервер указывает UTF-8</span>
                <?php elseif ( 'missing' === $check['status'] ) : ?>
                    <span style="color:#b32d2e">⚠ Сервер не указывает UTF-8 или передаёт другую кодировку</span>
                <?php else : ?>
                    <span>Не удалось определить (проверьте адрес извне)</span>
                <?php endif; ?>
                <code><?php echo esc_html( $check['detail'] ); ?></code>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=vm-llms-editor' ) ); ?>">
                <?php wp_nonce_field( 'vm_llms_action', 'vm_llms_nonce' ); ?>
                <input type="hidden" name="vm_llms_action" value="check">
                <button type="submit" class="button">Проверить ещё раз</button>
            </form>
            <?php if ( 'missing' === $check['status'] && ! vm_llms_virtual_mode() ) : ?>
                <p><strong>Как исправить на сервере:</strong> для Nginx добавьте в конфигурацию сайта и примените изменение:</p>
                <pre style="padding:12px;overflow:auto;background:#f6f7f7">location = /llms.txt {
    types { }
    default_type text/plain;
    charset utf-8;
    try_files $uri =404;
    expires 1h;
}</pre>
                <p>Если доступа к Nginx нет, передайте этот блок техподдержке хостинга. Проверьте также кэш CDN (он может сохранять старый ответ).</p>
                <hr>
                <h3>Исправить без доступа к серверу</h3>
                <p>Плагин может отдавать <code>/llms.txt</code> через WordPress с корректным заголовком. <strong>После вашего согласия физический файл заменяется динамической выдачей</strong>, а текст сохраняется в базе WordPress. Перед включением проверим маршрут и итоговый HTTP-ответ; если сервер не поддерживает такой способ, переключение будет отменено.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=vm-llms-editor' ) ); ?>">
                    <?php wp_nonce_field( 'vm_llms_action', 'vm_llms_nonce' ); ?>
                    <input type="hidden" name="vm_llms_action" value="enable_virtual">
                    <button type="submit" class="button button-primary">Согласен — включить выдачу через WordPress</button>
                </form>
            <?php elseif ( vm_llms_virtual_mode() ) : ?>
                <p>Режим работает только при активном плагине. При штатной деактивации плагин попытается восстановить физический файл.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=vm-llms-editor' ) ); ?>">
                    <?php wp_nonce_field( 'vm_llms_action', 'vm_llms_nonce' ); ?>
                    <input type="hidden" name="vm_llms_action" value="disable_virtual">
                    <button type="submit" class="button">Вернуться к физическому файлу</button>
                </form>
            <?php elseif ( 'unknown' === $check['status'] ) : ?>
                <p>При запросе с вашего сервера не удалось проверить HTTP-заголовок. Это не означает, что файл повреждён. Проверьте его из браузера или командой <code>curl -I <?php echo esc_html( $url ); ?></code>.</p>
            <?php endif; ?>
            <p class="description">Проверка выполняется с сервера WordPress и кэшируется на 5 минут. Из-за ограничений Nginx или CDN некоторые варианты виртуальной выдачи могут быть недоступны. Текст хранится в UTF-8 без BOM.</p>
        </div>
    </div>
    <?php
}
