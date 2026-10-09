<?php
/**
 * Plugin Name: LLMS.txt Editor
 * Plugin URI: https://ворд-пресс.рф
 * Description: Создаёт файл llms.txt в корне сайта и позволяет редактировать его содержимое в админке WordPress.
 * Version: 1.1.1
 * Author: Valery Molodtsov
 * Author URI: https://ворд-пресс.рф
 * License: GPL-2.0-or-later
 * Text Domain: llms-txt-editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Путь к публичному корню сайта, включая установки WordPress в подкаталоге. */
function vm_llms_file_path() {
    if ( ! function_exists( 'get_home_path' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    return trailingslashit( get_home_path() ) . 'llms.txt';
}

/** Записывает обычный текст в файл, не используя базу данных. */
function vm_llms_write_file( $content ) {
    $path = vm_llms_file_path();
    $directory = dirname( $path );

    if ( ! is_dir( $directory ) ) {
        return new WP_Error( 'llms_missing_root', 'Публичная корневая папка сайта не найдена.' );
    }

    // Не следуем за символическими ссылками и не заменяем каталоги.
    if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
        return new WP_Error( 'llms_invalid_file', 'По пути llms.txt находится не обычный файл. Запись отменена.' );
    }

    if ( file_exists( $path ) && ! is_writable( $path ) ) {
        return new WP_Error( 'llms_file_readonly', 'Файл llms.txt недоступен для записи. Проверьте права доступа.' );
    }

    if ( ! file_exists( $path ) && ! is_writable( $directory ) ) {
        return new WP_Error( 'llms_directory_readonly', 'Корневая папка сайта недоступна для записи. Проверьте права доступа.' );
    }

    if ( strlen( $content ) > 1024 * 1024 ) {
        return new WP_Error( 'llms_too_large', 'Размер файла не должен превышать 1 МБ.' );
    }

    $bytes = @file_put_contents( $path, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

    if ( false === $bytes || $bytes !== strlen( $content ) ) {
        return new WP_Error( 'llms_write_failed', 'Не удалось записать файл llms.txt. Проверьте права доступа и свободное место.' );
    }

    return true;
}

/** Создаёт минимальный стартовый файл, только если он ещё не существует. */
function vm_llms_ensure_file() {
    $path = vm_llms_file_path();

    if ( file_exists( $path ) || is_link( $path ) ) {
        return true;
    }

    $name = trim( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
    $description = trim( wp_strip_all_tags( get_bloginfo( 'description' ) ) );
    $content = '# ' . ( '' !== $name ? $name : 'Название сайта' ) . "\n";

    if ( '' !== $description ) {
        $content .= "\n> " . $description . "\n";
    }

    $content .= "\n- [Главная](" . home_url( '/' ) . "): Главная страница сайта.\n";

    return vm_llms_write_file( $content );
}

/**
 * Корректная UTF-8 отдача физического llms.txt на серверах Apache.
 *
 * Для обычного статического файла WordPress не выполняется, поэтому хуки
 * send_headers / wp_headers здесь не помогают. Меняем только заголовки
 * llms.txt, не трогая остальные TXT-файлы сайта.
 *
 * Для Nginx с прямой отдачей статики нужна настройка самого веб-сервера.
 */
function vm_llms_ensure_utf8_header() {
    $htaccess = trailingslashit( dirname( vm_llms_file_path() ) ) . '.htaccess';

    // Не меняем символические ссылки и не переписываем нестандартные объекты.
    if ( is_link( $htaccess ) || ( file_exists( $htaccess ) && ! is_file( $htaccess ) ) ) {
        return new WP_Error( 'llms_htaccess_invalid', 'Файл .htaccess имеет неподдерживаемый тип. Проверьте настройку кодировки вручную.' );
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

    // Не перезаписываем .htaccess при каждом посещении редактора.
    if ( is_file( $htaccess ) && is_readable( $htaccess ) ) {
        $existing = @file_get_contents( $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false !== $existing ) {
            $begin = strpos( $existing, '# BEGIN LLMS TXT EDITOR' );
            $end   = strpos( $existing, '# END LLMS TXT EDITOR' );
            if ( false !== $begin && false !== $end && $end > $begin ) {
                $block = substr( $existing, $begin, $end - $begin );
                if ( false !== strpos( $block, 'AddCharset UTF-8 .txt' )
                    && false !== strpos( $block, 'Header set Content-Type "text/plain; charset=UTF-8"' ) ) {
                    return true;
                }
            }
        }
    }

    if ( file_exists( $htaccess ) && ! is_writable( $htaccess ) ) {
        return new WP_Error( 'llms_htaccess_readonly', 'Нет прав записи в .htaccess. Для корректного отображения llms.txt необходимо настроить HTTP-кодировку на сервере.' );
    }

    if ( ! file_exists( $htaccess ) && ! is_writable( dirname( $htaccess ) ) ) {
        return new WP_Error( 'llms_htaccess_root_readonly', 'Нельзя создать .htaccess. Для корректного отображения llms.txt необходимо настроить HTTP-кодировку на сервере.' );
    }

    if ( ! function_exists( 'insert_with_markers' ) ) {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }

    if ( ! insert_with_markers( $htaccess, 'LLMS TXT EDITOR', $rules ) ) {
        return new WP_Error( 'llms_htaccess_failed', 'Не удалось установить UTF-8 для llms.txt в .htaccess. Проверьте конфигурацию веб-сервера.' );
    }

    return true;
}

/** Создать файл и попытаться настроить заголовок при первой активации. */
function vm_llms_activate() {
    $created = vm_llms_ensure_file();
    if ( ! is_wp_error( $created ) ) {
        vm_llms_ensure_utf8_header();
    }
}
register_activation_hook( __FILE__, 'vm_llms_activate' );

/** Отдельный пункт в левом меню админки. */
add_action( 'admin_menu', function () {
    add_menu_page(
        'Редактор llms.txt',
        'LLMS.txt',
        'manage_options',
        'vm-llms-editor',
        'vm_llms_render_editor',
        'dashicons-media-text',
        81
    );
} );

/** Форма редактирования и сохранения с проверкой прав и nonce. */
function vm_llms_render_editor() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Недостаточно прав для редактирования файла.' ) );
    }

    $error = '';
    $saved = false;
    $draft = null;
    $header_error = null;

    // Срабатывает и после обновления плагина без повторной активации.
    $header_result = vm_llms_ensure_utf8_header();
    if ( is_wp_error( $header_result ) ) {
        $header_error = $header_result->get_error_message();
    }

    if ( isset( $_POST['vm_llms_submit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        check_admin_referer( 'vm_llms_save_action', 'vm_llms_nonce' );

        if ( ! isset( $_POST['vm_llms_content'] ) || ! is_string( $_POST['vm_llms_content'] ) ) {
            $error = 'Содержимое файла не получено.';
        } else {
            $draft = wp_unslash( $_POST['vm_llms_content'] );
            $draft = str_replace( array( "\r\n", "\r" ), "\n", $draft );

            if ( strpos( $draft, "\0" ) !== false ) {
                $error = 'Текст содержит недопустимые нулевые байты.';
            } elseif ( ! seems_utf8( $draft ) ) {
                $error = 'Используйте текст в кодировке UTF-8.';
            } else {
                $result = vm_llms_write_file( $draft );

                if ( is_wp_error( $result ) ) {
                    $error = $result->get_error_message();
                } else {
                    // Остаёмся на текущей странице: сохраняем введённый текст
                    // в редакторе и показываем подтверждение без перенаправления.
                    $saved = true;
                }
            }
        }
    }

    if ( null === $draft ) {
        $created = vm_llms_ensure_file();

        if ( is_wp_error( $created ) ) {
            $error = $created->get_error_message();
        }
    }

    $path = vm_llms_file_path();
    $content = '';

    if ( null !== $draft ) {
        $content = $draft;
    } elseif ( is_file( $path ) && is_readable( $path ) && ! is_link( $path ) ) {
        $read_content = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false !== $read_content ) {
            $content = $read_content;
        } else {
            $error = 'Не удалось прочитать llms.txt. Проверьте права доступа.';
        }
    } elseif ( ! $error ) {
        $error = 'Файл llms.txt не найден или недоступен для чтения.';
    }
    ?>
    <div class="wrap">
        <h1>Редактор llms.txt</h1>
        <p>Изменяйте текстовый файл <code>llms.txt</code> в корне сайта. Можно использовать Markdown.</p>
        <p>
            <strong>Адрес файла:</strong>
            <a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a>
        </p>
        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p>
                <strong>Файл llms.txt сохранён.</strong>
                <a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener noreferrer">Открыть файл в новой вкладке ↗</a>
            </p></div>
        <?php endif; ?>
        <?php if ( $error ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
        <?php endif; ?>
        <?php if ( $header_error ) : ?>
            <div class="notice notice-warning"><p><?php echo esc_html( $header_error ); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=vm-llms-editor' ) ); ?>">
            <?php wp_nonce_field( 'vm_llms_save_action', 'vm_llms_nonce' ); ?>
            <label for="vm_llms_content" class="screen-reader-text">Содержимое llms.txt</label>
            <textarea id="vm_llms_content" name="vm_llms_content" class="large-text code" rows="24" style="max-width: 1100px;" spellcheck="false"><?php echo esc_textarea( $content ); ?></textarea>
            <p class="submit" style="display: flex; align-items: center; flex-wrap: wrap; gap: 12px;">
                <input type="submit" name="vm_llms_submit" id="submit" class="button button-primary" value="Сохранить llms.txt">
                <a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener noreferrer">Открыть llms.txt ↗</a>
            </p>
        </form>
        <p class="description">Сохраняется UTF-8 без BOM. Для Apache плагин добавляет правило только для llms.txt в .htaccess; для Nginx с прямой отдачей статики может потребоваться настройка сервера. При удалении плагина llms.txt не удаляется.</p>
        <details style="margin-top: 12px; max-width: 1100px;">
            <summary>Если кириллица всё ещё отображается неправильно</summary>
            <p>Проверьте HTTP-заголовок: <code>curl -I <?php echo esc_html( home_url( '/llms.txt' ) ); ?></code>. Нужно <code>Content-Type: text/plain; charset=utf-8</code>. У Nginx, CDN и прокси могут быть собственные настройки кодировки и кэша.</p>
            <p>Для Nginx (в конфигурации сайта, не в WordPress):</p>
            <pre style="white-space: pre-wrap; background: #f6f7f7; padding: 10px;">location = /llms.txt {
    types { }
    default_type "text/plain; charset=utf-8";
    try_files $uri =404;
}</pre>
        </details>
    </div>
    <?php
}
