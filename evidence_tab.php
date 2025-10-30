<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2021-2024 Petr Macek                                      |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | https://github.com/cacti/                                               |
 | https://www.cacti.net/                                                  |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include_once('./include/auth.php');
include_once('./lib/snmp.php');
include_once('./plugins/evidence/include/functions.php');
include_once('./plugins/evidence/include/arrays.php');

set_default_action();

$selectedTheme = get_selected_theme();

switch (get_request_var('action')) {
	case 'ajax_hosts':

		$sql_where = '';
		get_allowed_ajax_hosts(true, 'applyFilter', $sql_where);

		break;

	case 'setting':
		evidence_save_settings();
		break;

	case 'find':
		general_header();
		evidence_display_form();
		evidence_find();
		bottom_footer();

		break;

        default:
		general_header();
		evidence_display_form();
		evidence_stats();
		bottom_footer();

		break;
}

function evidence_display_form() {
	global $config, $entities, $datatypes;

	$evidence_records   = read_config_option('evidence_records');
	$evidence_frequency = read_config_option('evidence_frequency');

	print get_md5_include_js($config['base_path'] . '/plugins/evidence/evidence.js');

	$host_where = '';

	$host_id = get_filter_request_var('host_id');
	$template_id = get_filter_request_var('template_id');
	$scan_date = get_filter_request_var('scan_date', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', 'default' => -1)));
	$find_text = get_filter_request_var('find_text', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z0-9_\-\.:\+ ]+)$/', 'default' => 'INCORRECT: ' . get_nfilter_request_var('find_text'))));

	form_start(htmlspecialchars(basename($_SERVER['PHP_SELF'])), 'form_evidence');

	html_start_box('<strong>Evidence</strong>', '100%', '', '3', 'center', '');

	print "<tr class='even noprint'>";
	print "<td>";
	print "<form id='form_devices'>";
	print "<table class='filterTable'>";
	print "<tr>";

	print html_host_filter($host_id, 'applyFilter', $host_where, false, true);

	print "<td>";
	print __('Template');
	print "</td>";
	print "<td>";

	print "<select id='template_id' name='template_id'>";
	print "<option value='-1'" . (get_filter_request_var('template_id') == '-1' ? ' selected' : '') . '>' . __('Any') . '</option>';

	$templates = db_fetch_assoc('SELECT id, name FROM host_template');

	if (cacti_sizeof($templates)) {
		foreach ($templates as $template) {
			print '<option value="' . $template['id'] . '"' .
			(get_filter_request_var('template_id') == $template['id'] ? ' selected="selected"' : '') . '>' .
			html_escape($template['name']) . '</option>';
		}
	}

	print '</select>';
	print '</td>';

	print '<td>';
	print __('Scan date', 'evidence');
	print '</td>';
	print '<td>';

	print '<select id="scan_date" name="scan_date">';
	print '<option value="-1" ' . ($scan_date == -1 ? 'selected="selected"' : '') . '>' . __('All', 'evidence') . '</option>';

	$scan_dates = array_column(db_fetch_assoc('SELECT DISTINCT(scan_date) FROM plugin_evidence_snmp_info
		UNION SELECT DISTINCT(scan_date) FROM plugin_evidence_entity
		UNION SELECT DISTINCT(scan_date) FROM plugin_evidence_mac
		UNION SELECT DISTINCT(scan_date) FROM plugin_evidence_ip
		UNION SELECT DISTINCT(scan_date) FROM plugin_evidence_vendor_specific
		ORDER BY scan_date DESC'), 'scan_date');

	if (cacti_sizeof($scan_dates)) {
		foreach ($scan_dates as $sdate) {
			print '<option value="' . $sdate . '" ' . 
				($scan_date == $sdate ? ' selected="selected"' : '') . 
				'>' . $sdate . '</option>';
		}
	}

	print '</select>';
	print '</td>';
	print '<td>';
	print '<input type="submit" class="ui-button ui-corner-all ui-widget" id="refresh" value="' . __('Go') . '" title="' . __esc('Find') . '">';
	print '<input type="button" class="ui-button ui-corner-all ui-widget" id="clear" value="' . __('Clear') . '" title="' . __esc('Clear Filters') . '">';
	print '<input type="hidden" name="action" value="find">';
	print '</td>';
	print '</tr>';
	print '</table>';

	print "<table class='filterTable'>";
	print '<tr>';
	print '<td>';
	print 'Search';
	print '</td>';
	print '<td>';
	print '<input type="text" name="find_text" id="find_text" value="' . $find_text . '">';
	print '</td>';
	print '<td>';
	print 'You can search serial number, firmware version, ip, mac address,...';
	print '</td>';
	print '</tr>';
	print '</table>';

	print "<table class='filterTable'>";
	print '<tr>';
	print '<td>';
	evidence_show_checkboxes();
	print '</td>';
	print '</tr>';
	print '</table>';

	form_end(false);

	html_end_box();
}


function evidence_find() {
	global $entities;

	$templates = db_fetch_assoc('SELECT id, name FROM host_template');

	if (in_array(get_filter_request_var('host_id'), plugin_evidence_get_allowed_devices($_SESSION['sess_user_id'], true))) {
		$host_id = get_filter_request_var('host_id');
	}

	$scan_date = get_filter_request_var ('scan_date', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', 'default' => -1)));

	if (in_array(get_filter_request_var('template_id'), array_column($templates, 'id'))) {
		$template_id = get_filter_request_var('template_id');
	}

	$find_text = get_filter_request_var ('find_text', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z0-9_\-\.:\+ ]+)$/')));
	if (empty($find_text)) {
		unset($find_text);
	}

	if (isset($find_text) && (strlen($find_text) < 3 || strlen($find_text) > 20)) {
		print __('Search string must be 3-20 characters', 'evidence');
		return false;
	}

	if (isset($host_id)) {
		evidence_show_host_data($host_id, $scan_date);
	} else if (isset($template_id)) {
		$hosts = db_fetch_assoc_prepared('SELECT id FROM host
			WHERE host_template_id = ?',
			array($template_id));

		if (cacti_sizeof($hosts) > 0) {
			evidence_show_checkboxes();

			foreach ($hosts as $host) {
				evidence_show_host_data($host['id'], $scan_date);
			}
		}
	}

	if (isset($find_text)) {
		plugin_evidence_find();
	}

	if (!isset($host_id) && !isset($template_id)) {
		print __('Select any device or template', 'snver');
	}
}

function evidence_stats() {
	global $config;

	$evidence_records   = read_config_option('evidence_records');
	$evidence_frequency = read_config_option('evidence_frequency');

	if ($evidence_frequency == 0 || $evidence_records == 0) {
		print __('No data. Allow periodic scan and store history in settings');
	}

	print '<br/><br/>';
	print __('You can display all information about specific host, all devices with the same template.') . '<br/>';
	print __('You can search any string in all data.') . '<br/>';
	print __('Note when using Scan Date - Only the data that changed at the moment of Scan_date is displayed. Data not changed at that time is not displayed.') . '<br/>';

	$dev = db_fetch_cell ('SELECT SUM(total) from ( SELECT COUNT(DISTINCT(host_id)) AS total FROM plugin_evidence_entity 
		UNION SELECT COUNT(DISTINCT(host_id)) AS total FROM plugin_evidence_mac
		UNION SELECT COUNT(DISTINCT(host_id)) AS total FROM plugin_evidence_ip 
		UNION SELECT COUNT(DISTINCT(host_id)) AS total FROM plugin_evidence_vendor_specific) AS t1');
	$vnd = db_fetch_cell ('SELECT count(distinct(organization_id)) FROM plugin_evidence_entity');
	$ent = db_fetch_cell ('SELECT COUNT(*) FROM plugin_evidence_entity');
	$mac = db_fetch_cell ('SELECT COUNT(distinct(mac)) FROM plugin_evidence_mac');
	$ip  = db_fetch_cell ('SELECT COUNT(distinct(ip_mask)) FROM plugin_evidence_ip');
	$ven = db_fetch_cell ('SELECT COUNT(*) FROM plugin_evidence_vendor_specific');
	$old = db_fetch_cell ('SELECT MIN(scan_date) FROM plugin_evidence_entity');

	print '<br/><br/>';
	print '<strong>' . __('Number of records') . ':</strong><br/>';
	print 'Devices: ' . $dev . ' records<br/>';
	print 'Entity MIB: ' . $ent . ', records, ' . $vnd . ' vendors<br/>';
	print 'Unique MAC addresses: ' . $mac . '<br/>';
	print 'Unique IP addresses: ' . $ip . '<br/>';
	print 'Vendor specific data: ' . $ven . '<br/>';
	print 'Oldest record: ' . $old . '<br/>';
	print '<br/><br/>';

	$treemap = array(
		'label' => array(),
		'data'  => array(),
	);


	$vendors = db_fetch_assoc('SELECT count(distinct(host_id)) AS `count`, organization
		FROM plugin_evidence_entity AS pee
		JOIN plugin_evidence_organization AS peo
		ON pee.organization_id = peo.id
		GROUP BY pee.organization_id');

	if (cacti_sizeof($vendors)) {

		$data = array();

		foreach ($vendors as $vendor) {
			array_push($treemap['label'], $vendor['organization']);
			array_push($treemap['data'], $vendor['count']);
		}
		
		print '<strong>Vendors:</strong><br />';
		evidence_treemap('Vendors', $treemap);
	}
}

function evidence_show_checkboxes() {
	global $datatypes;

	print "<table class='filterTable'>";

	print '<tr>';
	print '<td>' . __('Show or hide', 'evidence') . ':</td>';

	foreach ($datatypes as $key => $value) {
		print '<td>';
		print '<input type="checkbox" id="ch_' . $key . '" name="ch_' . $key . '" value="1" ' . (read_user_setting('evidence_display_' . $key, true) ? ' checked="checked" ' : '') . '>';
		print '<label for="ch_' . $key . '">' . $value . '</label>';
		print '</td>';
	}

	print '<td>';
	print '</td>';
	print '<td>';
	print '<input type="checkbox" id="ch_expand" name="ch_expand" value="1"><label for="ch_expand" class="bold">Expand all dates</label>';
	print '<input type="checkbox" id="ch_expand_latest" name="ch_expand_latest" value="1"><label for="ch_expand_latest" class="bold">Expand latest date</label>';
	print '</td>';

	print '</tr>';
	print '</table>';
}


function evidence_treemap($title, $data) {

	$xid = 'x'. substr(md5($title), 0, 7);

	echo "<div class='chart_wrapper center' id=\"treemap_$xid\"></div>";
	echo '<script type="text/javascript">';
	echo 'treemap_' . $xid . ' = bb.generate({';
	echo " bindto: \"#treemap_$xid\",";

	echo " size: {";
	echo "  width: 450,";
	echo "  height: 200";
	echo " },";

	echo " data: {";
	echo "  columns: [";

	foreach ($data['data'] as $key => $value) {
		echo "['" . $data['label'][$key] . "', " . $value . "],";
	}

	echo "  ],";
	echo "  type: 'treemap',";
	echo "  labels: {";
	echo "    colors: '#fff'";
	echo "  }";
	echo "  },";

	echo "  treemap: {";
	echo "    label: {";
	echo "      threshold: 0.03, show: true,";
	echo "    }";
	echo "  },";

	echo "});";
	echo "</script>";
}


function evidence_save_settings() {
	switch (get_nfilter_request_var('what')) {
		case 'info':
			if (read_user_setting('evidence_display_info', true)) {
				set_user_setting('evidence_display_info', '');
			} else {
				set_user_setting('evidence_display_info', 'on');
			}
			break;

		case 'entity':
			if (read_user_setting('evidence_display_entity', true)) {
				set_user_setting('evidence_display_entity', '');
			} else {
				set_user_setting('evidence_display_entity', 'on');
			}
			break;

		case 'mac':
			if (read_user_setting('evidence_display_mac', true)) {
				set_user_setting('evidence_display_mac', '');
			} else {
				set_user_setting('evidence_display_mac', 'on');
			}
			break;

		case 'ip':
			if (read_user_setting('evidence_display_ip', true)) {
				set_user_setting('evidence_display_ip', '');
			} else {
				set_user_setting('evidence_display_ip', 'on');
			}
			break;

		case 'spec':
			if (read_user_setting('evidence_display_spec', true)) {
				set_user_setting('evidence_display_spec', '');
			} else {
				set_user_setting('evidence_display_spec', 'on');
			}
			break;

		case 'opt':
			if (read_user_setting('evidence_display_opt', true)) {
				set_user_setting('evidence_display_opt', '');
			} else {
				set_user_setting('evidence_display_opt', 'on');
			}
			break;
	}
}