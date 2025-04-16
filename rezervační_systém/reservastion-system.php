<?php
/*
Plugin Name: Zápisový Rezervační systém
Description: Plugin pro správu rezervací a zobrazení časových slotů pro uživatele.
Version: 1.0
Author: Jan Veselský
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
ob_start();
session_start();
register_activation_hook( __FILE__, 'rs_activate_plugin' );
function rs_enqueue_styles(): void {
	wp_enqueue_style( 'rs-style', plugin_dir_url( __FILE__ ) . 'assets/style.css', array(), filemtime( plugin_dir_path( __FILE__ ) . 'assets/style.css' ) );
}

add_action( 'wp_enqueue_scripts', 'rs_enqueue_styles' );
add_action( 'admin_enqueue_scripts', 'rs_enqueue_styles' );

function set_message($message, $type): void
{
    if (!session_id()) {
        session_start();
    }

    $_SESSION['flash_message'] = [
        'text' => $message,
        'type' => $type
    ];

    echo '<script>window.location.href = "' . $_SERVER['HTTP_REFERER'] . '";</script>';
    exit;
}
function rs_get_time_settings(): array {
	$start_time = get_option( 'rs_start_time', '09:00' );
	$end_time = get_option( 'rs_end_time', '16:30' );
	$interval = get_option( 'rs_time_interval', 15 );

	return compact('start_time', 'end_time', 'interval');
}

function rs_activate_plugin(): void {
	global $wpdb;
	$table_name = $wpdb->prefix . 'reservations';
	$capacity_table = $wpdb->prefix . 'reservation_slots';

	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            time varchar(5) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

	}

	if ( $wpdb->get_var( "SHOW TABLES LIKE '$capacity_table'" ) != $capacity_table ) {
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE $capacity_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            time varchar(5) NOT NULL,
            capacity int NOT NULL DEFAULT 6,
            PRIMARY KEY  (id),
            UNIQUE KEY time (time)
        ) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	$times = rs_generate_times();

	foreach ( $times as $time ) {
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $capacity_table WHERE time = %s", $time
		) );

		if ( $existing == 0 ) {
			$wpdb->insert(
				$capacity_table,
				array(
					'time'     => $time,
					'capacity' => 6,
				),
				array(
					'%s',
					'%d',
				)
			);
		}
	}
	$all_times_in_db = $wpdb->get_results( "SELECT time FROM $capacity_table", ARRAY_A );
	$all_times_in_db = array_map( function ( $item ) {
		return $item['time'];
	}, $all_times_in_db );

	$times_to_remove = array_diff( $all_times_in_db, $times );

	foreach ( $times_to_remove as $time ) {
		$wpdb->delete( $capacity_table, array( 'time' => $time ), array( '%s' ) );
	}
}

function rs_get_config() {
	$config = get_option( 'rs_config', array() );

	if ( ! isset( $config['reservations_enabled'] ) ) {
		$config['reservations_enabled'] = 0;
	}

	return $config;
}

function rs_reservation_table_shortcode() {
	$data   = rs_load_data();
	$times  = rs_generate_times();
	$config = rs_get_config();

	ob_start();
	?>
    <div class="rs-container">
        <h1 class="rs-title">Rezervační Tabulka</h1>
		<?php
        if (!empty($_SESSION['flash_message'])) {
            $message = $_SESSION['flash_message']['text'];
            $type = $_SESSION['flash_message']['type'];

            echo '<div class="' . esc_attr($type) . '">' . esc_html($message) . '</div>';

            unset($_SESSION['flash_message']);
        }
        ?>
		<?php if ( ! $config['reservations_enabled'] ) : ?>
            <p class="rs-error">Rezervace jsou momentálně uzavřeny.</p>
		<?php else : ?>
            <table class="rs-table">
                <thead>
                <tr>
                    <th class="rs-user-info-row">Čas</th>
                    <th class="rs-user-info-row">Rezervace</th>
                    <th>Jména</th>
                </tr>
                </thead>
                <tbody>
				<?php foreach ( $times as $time ) :
					$reservations = $data[ $time ]['reservations'] ?? [];
					$capacity = $data[ $time ]['capacity'] ?? 6;
                    usort($reservations, function($a, $b) {
                        return strcmp($a['name'], $b['name']);
                    });
					?>
                    <tr>
                        <td><?php echo esc_html( $time ); ?></td>
                        <td><?php echo count( $reservations ) . '/' . esc_html( $capacity ); ?></td>
                        <td>
							<?php if ( ! empty( $reservations ) ) : ?>
                                <ul class="rs-names-list">
									<?php foreach ( $reservations as $res ) : ?>
                                        <li class="rs-name"><?php echo esc_html( $res['name'] ); ?></li>
									<?php endforeach; ?>
                                </ul>
							<?php else : ?>
                                <p class="rs-no-reservations">Žádné rezervace</p>
							<?php endif; ?>
                        </td>
                    </tr>
				<?php endforeach; ?>
                </tbody>
            </table>
            <form method="POST" action="<?php echo esc_url( $_SERVER['REQUEST_URI'] ); ?>" class="rs-form">
				<?php wp_nonce_field( 'rs_reservation_action', 'rs_reservation_nonce' ); ?>
                <p class="rs-name">Zarezervujte se</p>
                <label class="rs-label">
                    <input type="text" autocomplete="Neznámý" name="name" class="rs-input" placeholder="Vaše jméno" required/>
                </label>
                <label class="rs-label">
                    <select name="time" class="rs-select" required>
                        <?php
                        foreach ( $times as $time ) :
                            $reservations = $data[ $time ]['reservations'] ?? [];
                            $capacity = $data[ $time ]['capacity'] ?? 6;
                            $existing_reservations_count = count( $reservations );
                            if ( $existing_reservations_count >= $capacity ) {
                                continue;
                            }
                            ?>
                            <option value="<?php echo esc_attr( $time ); ?>"><?php echo esc_html( $time ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" name="submit_reservation" class="rs-reserve-button">Rezervovat</button>
            </form>
		<?php endif; ?>
    </div>
	<?php
	return ob_get_clean();
}

add_shortcode( 'reservation_table', 'rs_reservation_table_shortcode' );

function rs_handle_reservation_submission(): void {
	if ( isset( $_POST['submit_reservation'] ) ) {
		if ( ! isset( $_POST['rs_reservation_nonce'] ) || ! wp_verify_nonce( $_POST['rs_reservation_nonce'], 'rs_reservation_action' ) ) {
			return;
		}

		$name = sanitize_text_field( $_POST['name'] );
		$time = sanitize_text_field( $_POST['time'] );

		global $wpdb;
		$reservations_table = $wpdb->prefix . 'reservations';
		$capacity_table     = $wpdb->prefix . 'reservation_slots';

		$capacity = $wpdb->get_var( $wpdb->prepare(
			"SELECT capacity FROM $capacity_table WHERE time = %s",
			$time
		) );

		if ( is_null( $capacity ) ) {
			$capacity = 6;
		}

		$existing_reservations_count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $reservations_table WHERE time = %s",
			$time
		) );

		if ( $existing_reservations_count >= $capacity ) {
            set_message('Kapacita pro tento čas je již plná.', 'rs-error' );
		}

		$existing_reservation = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $reservations_table WHERE name = %s",
			$name
		) );

		if ( $existing_reservation > 0 ) {
            set_message('Rezervace pro toto jméno již existuje! V případě shody jmen napište za jméno dítěte do závorek jméno rodiče! Pokud jste jméno nezadávali vy, tak se obraťte na školku!', 'rs-error');
		}

		$insert_result = $wpdb->insert(
			$reservations_table,
			array(
				'name' => $name,
				'time' => $time,
			),
			array(
				'%s',
				'%s',
			)
		);

		if ( $insert_result !== false ) {
            set_message('Rezervace byla úspěšně provedena!', 'rs-message-success' );
		} else {
			set_message('Chyba při ukládání rezervace.', 'rs-message-error' );
		}
	}
}

add_action( 'template_redirect', 'rs_handle_reservation_submission' );

function rs_admin_menu(): void {
	add_menu_page( 'Rezervace', 'Rezervace', 'manage_options', 'rs-admin', 'rs_admin_page', 'dashicons-calendar-alt' );
}

add_action( 'admin_menu', 'rs_admin_menu' );


function rs_reset_plugin(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;
	$reservations_table = $wpdb->prefix . 'reservations';
	$capacity_table     = $wpdb->prefix . 'reservation_slots';

	$wpdb->query( "DROP TABLE IF EXISTS $reservations_table" );
	$wpdb->query( "DROP TABLE IF EXISTS $capacity_table" );

	update_option('rs_start_time', '09:00');
	update_option('rs_end_time', '16:30');
	update_option('rs_time_interval', 15);

	rs_activate_plugin();

	delete_option( 'rs_config' );

	set_message('Plugin byl úspěšně resetován do výchozího nastavení.', 'updated');
}

function rs_admin_reset_button(): void {
	if ( isset( $_POST['reset_plugin'] ) ) {
		rs_reset_plugin();
	}
	?>
    <form method="POST">
        <button type="submit" name="reset_plugin" class="button button-secondary"
                onclick="return confirm('Opravdu chcete resetovat plugin? Všechna data budou smazána!');">Resetovat do
            výchozího nastavení
        </button>
    </form>
	<?php
}

function rs_update_time_range_settings(): void {
	if (isset($_POST['update_time_range']) && isset($_POST['rs_update_time_range_nonce']) && wp_verify_nonce($_POST['rs_update_time_range_nonce'], 'rs_update_time_range_action')) {

        $start_time = sanitize_text_field($_POST['start_time']);
		$end_time = sanitize_text_field($_POST['end_time']);
		$time_interval = intval($_POST['time_interval']);

		update_option('rs_start_time', $start_time);
		update_option('rs_end_time', $end_time);
		update_option('rs_time_interval', $time_interval);

		rs_activate_plugin();
        set_message('Časové nastavení bylo úspěšně aktualizováno.', 'updated');
    }
}
add_action('admin_init', 'rs_update_time_range_settings');


function rs_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$table_name     = $wpdb->prefix . 'reservations';
	$capacity_table = $wpdb->prefix . 'reservation_slots';
	$data           = rs_load_data();
	$times          = rs_generate_times();

	if ( isset( $_POST['update_capacity'] ) ) {
		$time     = sanitize_text_field( $_POST['time'] );
		$capacity = intval( $_POST['capacity'] );

		if ( $capacity > 0 ) {
			$updated = $wpdb->update(
				$capacity_table,
				[ 'capacity' => $capacity ],
				[ 'time' => $time ],
				[ '%d' ],
				[ '%s' ]
			);

			if ( $updated !== false ) {
				set_message('Kapacita byla úspěšně změněna.', 'updated');
			} else {
                set_message('Chyba při aktualizaci kapacity.','error');
			}
		}
	}


	if ( isset( $_POST['delete_reservation'] ) ) {
		$name = sanitize_text_field( $_POST['delete_reservation'] );
		global $wpdb;
		$table_name = $wpdb->prefix . 'reservations';

		$reservation_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table_name WHERE name = %s LIMIT 1", $name
		) );

		if ( $reservation_id ) {
			$wpdb->delete( $table_name, [ 'id' => $reservation_id ], [ '%d' ] );
            set_message('Rezervace byla úspěšně odstraněna.','updated');
		} else {
            set_message('Rezervace pro toto jméno neexistuje.','error');
		}
	}

	if ( isset( $_POST['delete_all_reservations_in_time'] ) ) {
		$time_to_delete = sanitize_text_field( $_POST['delete_time'] );
		$wpdb->delete( $table_name, [ 'time' => $time_to_delete ], [ '%s' ] );
		set_message('Všechny rezervace pro tento čas byly úspěšně odstraněny.','updated');
	}

    if ( isset( $_POST['delete_all_reservations'] ) ) {

        global $wpdb;
        $table_name = $wpdb->prefix . 'reservations';
        $wpdb->query( "DELETE FROM $table_name" );
        set_message('Všechny rezervace byly úspěšně odstraněny.','updated');
    }

	?>
    <div class="wrap rs-admin-container">
        <h1>Správa Rezervací</h1>
        <?php
        if (!empty($_SESSION['flash_message'])) {
            $message = $_SESSION['flash_message']['text'];
            $type = $_SESSION['flash_message']['type'];

            echo '<div class="' . esc_attr($type) . '">' . esc_html($message) . '</div>';

            unset($_SESSION['flash_message']);
        }
        ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'no_reservations') {
        echo '<div class="error"><p><strong>Žádné rezervace k exportu.</strong></p></div>';
        } ?>
        <h2>Nastavení</h2>
        <form method="POST">
			<?php wp_nonce_field( 'rs_update_settings_action', 'rs_update_settings_nonce' );
			$config = rs_get_config() ?>
            <p>
                <label for="reservations_enabled_on">
                    <input type="radio" name="reservations_enabled" value="1"
                           id="reservations_enabled_on" <?php checked( 1, $config['reservations_enabled'] ); ?>/>Zapnuto
                </label>
            </p>
            <p>
                <label for="reservations_enabled_off">
                    <input type="radio" name="reservations_enabled" value="0"
                           id="reservations_enabled_off" <?php checked( 0, $config['reservations_enabled'] ); ?>/>Vypnuto
                </label>
            </p>
            <p>
                <button type="submit" name="update_config" class="button button-primary">Uložit změny</button>
            </p>
        </form>
        <h2>Výchozí nastavení</h2>
        <div class="buttons-gap">
		    <?php rs_admin_reset_button() ?>
            <form method="POST" action="">
                <?php wp_nonce_field( 'delete_all_reservations_action', 'delete_all_reservations_nonce' ); ?>
                <input type="hidden" name="delete_all_reservations" value="1"/>
                <button type="submit" name="delete_all_reservations" class="button button-secondary" onclick="return confirm('Opravdu chcete smazat všechny rezervace?');">
                    Smazat všechny rezervace
                </button>
            </form>
        </div>
        <h2>Nastavení časového rozmezí</h2>
        <form method="POST">
			<?php wp_nonce_field( 'rs_update_time_range_action', 'rs_update_time_range_nonce' ); ?>
            <p>
                <label for="start_time">Počáteční čas:</label>
                <input type="time" name="start_time" id="start_time" value="<?php echo esc_attr( get_option( 'rs_start_time', '09:00' ) ); ?>" required/>
            </p>
            <p>
                <label for="end_time">Koncový čas:</label>
                <input type="time" name="end_time" id="end_time" value="<?php echo esc_attr( get_option( 'rs_end_time', '16:30' ) ); ?>" required/>
            </p>
            <p>
                <label for="time_interval">Interval (v minutách):</label>
                <input type="number" name="time_interval" id="time_interval" value="<?php echo esc_attr( get_option( 'rs_time_interval', 15 ) ); ?>" min="1" max="60" required/>
            </p>
            <button type="submit" name="update_time_range" class="button button-primary">Uložit časové nastavení
            </button>
        </form>
        <h2>Export Rezervací do Excelu</h2>
        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
            <label class="rs-excel-label">
            <input type="hidden" name="action" value="export_reservations_to_excel">
                <?php echo '<label class="rs-date-div">Vložte datum zápisu:<input type="date" name="datum" class="rs-date"  value="' . date('Y-m-d') . '" required></label>'?>
                <button type="submit" name="export_to_excel" class="button button-secondary">Exportovat do Excelu</button>
            </label>
        </form>
        <h2>Aktuální Rezervace</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
            <tr>
                <th class="time-row">Čas</th>
                <th>Jména</th>
                <th>Akce</th>
            </tr>
            </thead>
            <tbody>
			<?php foreach ( $times as $time ) :
				$reservations = $data[ $time ]['reservations'] ?? [];
				$capacity = $data[ $time ]['capacity'] ?? 6;
                usort($reservations, function($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
				?>
                <tr>
                    <td><?php echo esc_html( $time ) ?></td>
                    <td>
						<?php if ( ! empty( $reservations ) ) : ?>
                            <ul class="rs-names-list-admin">
								<?php foreach ( $reservations as $res ) : ?>
                                    <li class="rs-name"> <?php echo esc_html( $res['name'] ); ?>
                                        <form method="POST" action="">
                                            <input type="hidden" name="delete_reservation"
                                                   value="<?php echo esc_attr( $res['name'] ); ?>">
                                            <button type="submit" class="btn-delete"
                                                    onclick="return confirm('Opravdu chcete tuto rezervaci odstranit?');">
                                                Smazat
                                            </button>
                                        </form>
                                    </li>
								<?php endforeach; ?>
                            </ul>
						<?php else : ?>
                            <p>Žádné rezervace</p>
						<?php endif; ?>
                    </td>
                    <td>
                        <div class="rs-names-list-admin">
                            <form method="POST" class="rs-action-capacity" action="">
                                <input type="hidden" name="time" value="<?php echo esc_attr( $time ); ?>"/>
                                <label name="capacity" class="rs-label">
                                    <input type="number" name="capacity" value="<?php echo esc_attr( $capacity ); ?>"
                                           min="1" class="rs-capacity-input"/>
                                </label>
                                <button type="submit" name="update_capacity" class="rs-capacity-button">Upravit
                                    kapacitu
                                </button>
                            </form>
                            <form method="POST" class="rs-action-delete-all" action="">
                                <input type="hidden" name="delete_time" value="<?php echo esc_attr( $time ); ?>"/>
                                <button type="submit" name="delete_all_reservations_in_time" class="btn-delete big-btn-delete"
                                        onclick="return confirm('Opravdu chcete smazat všechny rezervace pro tento čas?');">
                                    Smazat všechny rezervace
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
			<?php endforeach; ?>
            </tbody>
        </table>
    </div>
	<?php
}


function rs_update_plugin_settings(): void {
	if ( isset( $_POST['update_config'] ) && isset( $_POST['rs_update_settings_nonce'] ) && wp_verify_nonce( $_POST['rs_update_settings_nonce'], 'rs_update_settings_action' ) ) {
		$config                         = get_option( 'rs_config' );
		$config['reservations_enabled'] = isset( $_POST['reservations_enabled'] ) ? (int) $_POST['reservations_enabled'] : 0;
		update_option( 'rs_config', $config );
        set_message('Nastavení bylo úspěšně aktualizováno.','updated');
	}
}

add_action( 'admin_init', 'rs_update_plugin_settings' );

function rs_load_data(): array {
	global $wpdb;
	$table_name     = $wpdb->prefix . 'reservations';
	$capacity_table = $wpdb->prefix . 'reservation_slots';

	$results = $wpdb->get_results( "SELECT name, time FROM $table_name", ARRAY_A );

	$data = array();
	foreach ( $results as $row ) {
		$data[ $row['time'] ]['reservations'][] = array( 'name' => $row['name'] );
	}

	$slots = $wpdb->get_results( "SELECT time, capacity FROM $capacity_table", ARRAY_A );

	foreach ( $slots as $slot ) {
		$data[ $slot['time'] ]['capacity'] = $slot['capacity'];
	}

	return $data;
}

function rs_generate_times(): array {
	$settings = rs_get_time_settings();
	$start_time = $settings['start_time'];
	$end_time = $settings['end_time'];
	$interval = $settings['interval'];

	$times = [];
	$current_time = strtotime($start_time);

	while ($current_time <= strtotime($end_time)) {
		$times[] = date('H:i', $current_time);
		$current_time = strtotime("+$interval minutes", $current_time);
	}

	return $times;
}

function rs_update_slots_after_time_change(): void {
	global $wpdb;
	$capacity_table = $wpdb->prefix . 'reservation_slots';

	$times = rs_generate_times();

	foreach ( $times as $time ) {
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $capacity_table WHERE time = %s", $time
		) );

		if ( $existing == 0 ) {
			$wpdb->insert(
				$capacity_table,
				array(
					'time'     => $time,
					'capacity' => 6,
				),
				array(
					'%s',
					'%d',
				)
			);
		}
	}
}

add_action( 'admin_post_update_time_range', 'rs_update_slots_after_time_change' );

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * @throws \PhpOffice\PhpSpreadsheet\Exception
 * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
 */
function rs_export_reservations_to_excel(): void {
	if (isset($_POST['export_to_excel'])) {
		global $wpdb;
		$reservations_table = $wpdb->prefix . 'reservations';
		$capacity_table = $wpdb->prefix . 'reservation_slots';

		$reservations = $wpdb->get_results("
			SELECT r.name, r.time, c.capacity 
			FROM $reservations_table r 
			JOIN $capacity_table c ON r.time = c.time 
			ORDER BY r.time ASC, r.name ASC
		", ARRAY_A);

		if (empty($reservations)) {
            wp_redirect(add_query_arg('error', 'no_reservations', wp_get_referer()));
            exit;
		}

		$autoload_path = plugin_dir_path(__FILE__) . 'lib/vendor/autoload.php';
		if (!file_exists($autoload_path)) {
			die("Autoload file nenalezen: " . $autoload_path);
		}
		require_once $autoload_path;

        $spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();

        $date = DateTime::createFromFormat('Y-m-d', $_POST["datum"]);
        $formattedDate = $date->format('d.m.Y');

        $sheet->mergeCells("A1:D1");
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        $sheet->getStyle('A1')->getFont()->setSize(16);
        $sheet->getStyle('A1')->getFont()->setBold(true);
        $sheet->setCellValue('A1', 'Elektronická rezervace času na '.$formattedDate);

		$sheet->setCellValue('A3', 'Čas');
		$sheet->setCellValue('B3', 'Ev. č.');
		$sheet->setCellValue('C3', 'Jméno dítěte');
		$sheet->setCellValue('D3', 'Poznámka');

		$row = 4;
		$current_time = '';
		$time_range_start = null;
        $count = 0;

		foreach ($reservations as $reservation) {
			$sheet->getStyle('A' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
			$sheet->getStyle('B' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
			$sheet->getStyle('C' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
			$sheet->getStyle('D' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

			if ($reservation['time'] !== $current_time) {
                $count = $count + $wpdb->get_var( $wpdb->prepare( "
                SELECT COUNT(*) 
                FROM {$wpdb->prefix}reservations 
                WHERE time = %s", $reservation['time']));
                if ($count > 43) {
                    $remaining = $count - $wpdb->get_var( $wpdb->prepare( "
                SELECT COUNT(*) 
                FROM {$wpdb->prefix}reservations 
                WHERE time = %s", $reservation['time']));
                    if ($time_range_start !== null) {
                        $end_row = $row - 1;
                        $sheet->mergeCells("A$time_range_start:A$end_row");
                        $sheet->getStyle("A$time_range_start:D$end_row")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THICK);
                        $sheet->getStyle('A' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);
                        $sheet->getStyle('B' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);
                        $sheet->getStyle('C' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);
                        $sheet->getStyle('D' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);
                    }
                    while ($remaining <= 45) {
                        $sheet->setCellValue('A' . $row, '');
                        $row++;
                        $remaining++;
                    }
                    $sheet->getStyle('A' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle('B' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle('C' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle('D' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                    $title = $row-3;
                    $sheet->mergeCells("A$title:D$title");
                    $sheet->getStyle('A' . $title)->getAlignment()->setHorizontal('center');
                    $sheet->getStyle('A' . $title)->getFont()->setSize(16);
                    $sheet->getStyle('A' . $title)->getFont()->setBold(true);
                    $sheet->setCellValue('A' . $title, 'Elektronická rezervace času na '.$formattedDate);
                    $sheet->setCellValue('A' . ($row - 1), 'Čas');
                    $sheet->setCellValue('B' . ($row - 1), 'Ev. č.');
                    $sheet->setCellValue('C' . ($row - 1), 'Jméno dítěte');
                    $sheet->setCellValue('D' . ($row - 1), 'Poznámka');
                    $count = 0 + $wpdb->get_var( $wpdb->prepare( "
                SELECT COUNT(*) 
                FROM {$wpdb->prefix}reservations 
                WHERE time = %s", $reservation['time']));
                } else {
                    if ($time_range_start !== null) {
                        $end_row = $row - 1;
                        $sheet->mergeCells("A$time_range_start:A$end_row");
                        $sheet->getStyle("A$time_range_start:D$end_row")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THICK);
                    }
                }
				$current_time = $reservation['time'];
				$sheet->setCellValue('A' . $row, $current_time);
				$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
				$sheet->getStyle('A' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
				$time_range_start = $row;
			}

			$sheet->setCellValue('B' . $row, '');
			$sheet->setCellValue('C' . $row, $reservation['name']);
			$sheet->setCellValue('D' . $row, '');

			$row++;
		}

		if ($time_range_start !== null) {
			$end_row = $row - 1;
			$sheet->mergeCells("A$time_range_start:A$end_row");
			$sheet->getStyle("A$time_range_start:D$end_row")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THICK);
		}

		$sheet->getColumnDimension('A')->setWidth(10);
		$sheet->getColumnDimension('B')->setWidth(7);
		$sheet->getColumnDimension('C')->setWidth(46);
		$sheet->getColumnDimension('D')->setWidth(26);

		$writer = new Xlsx($spreadsheet);

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment;filename="rezervace.xlsx"');
		header('Cache-Control: max-age=0');

		$writer->save('php://output');
		exit;
	}
}
add_action('admin_post_export_reservations_to_excel', 'rs_export_reservations_to_excel');

