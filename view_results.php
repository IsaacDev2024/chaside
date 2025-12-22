<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');

define('BLOCK_CHASIDE_QUESTIONS_PER_PAGE', 10);
define('BLOCK_CHASIDE_TOTAL_QUESTIONS', 98);

$courseid = required_param('courseid', PARAM_INT);
$userid = optional_param('userid', $USER->id, PARAM_INT);

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
// Verificar permisos (redirreción silenciosa como learning_style/personality_test)
if ($userid != $USER->id && !has_capability('block/chaside:viewreports', $context)) {
    redirect(new moodle_url('/course/view.php', array('id' => $courseid)));
}

$PAGE->set_url('/blocks/chaside/view_results.php', array('courseid' => $courseid, 'userid' => $userid));
$PAGE->set_heading($course->fullname);
$PAGE->set_context($context);
$PAGE->requires->css('/blocks/chaside/styles.css');

// Obtener los resultados del usuario (en cualquier curso)
$response = $DB->get_record('block_chaside_responses', array(
    'userid' => $userid
));

if (!$response) {
    echo $OUTPUT->header();
    echo html_writer::tag('div', get_string('test_not_found', 'block_chaside'), array('class' => 'alert alert-warning'));
    echo html_writer::link(
        new moodle_url('/blocks/chaside/view.php', array('courseid' => $courseid)),
        get_string('start_test', 'block_chaside'),
        array('class' => 'btn btn-primary')
    );
    echo $OUTPUT->footer();
    exit;
}

// Get user information BEFORE checking completion status
$user = $DB->get_record('user', array('id' => $userid)); 

$pagetitle = ($userid != $USER->id)
    ? get_string('viewing_results_of', 'block_chaside', fullname($user))
    : get_string('your_results', 'block_chaside');
$PAGE->set_title($pagetitle);

// Check if test is in progress (similar to personality_test)
if ($response->is_completed == 0) {
    echo $OUTPUT->header();
    
    // Calculate progress
    $answered = 0;
    for ($i = 1; $i <= BLOCK_CHASIDE_TOTAL_QUESTIONS; $i++) {
        $field = "q{$i}";
        if (isset($response->$field) && $response->$field !== null) {
            $answered++;
        }
    }
    $progress_percentage = round(($answered / BLOCK_CHASIDE_TOTAL_QUESTIONS) * 100, 1);
    
    echo '<div class="container-fluid">';
    echo '<div class="alert alert-warning" role="alert">';
    echo '<h4 class="alert-heading"><i class="fa fa-clock-o"></i> ' . get_string('test_in_progress', 'block_chaside') . '</h4>';
    echo '<p>' . get_string('test_in_progress_message', 'block_chaside', fullname($user)) . '</p>';
    echo '<hr>';
    echo '<p class="mb-1"><strong>' . get_string('progress_label', 'block_chaside') . ':</strong></p>';
    echo '<div class="progress mb-2" style="height: 30px;">';
    echo '<div class="progress-bar bg-warning" role="progressbar" style="width: ' . $progress_percentage . '%" aria-valuenow="' . $progress_percentage . '" aria-valuemin="0" aria-valuemax="100">';
    echo '<strong>' . $progress_percentage . '%</strong>';
    echo '</div>';
    echo '</div>';
    echo '<p><strong>' . get_string('has_answered', 'block_chaside') . ':</strong> ' . $answered . '/' . BLOCK_CHASIDE_TOTAL_QUESTIONS . ' ' . get_string('questions', 'block_chaside') . '</p>';
    
    // Special message if all questions answered but not submitted
    if ($answered == BLOCK_CHASIDE_TOTAL_QUESTIONS) {
        echo '<div class="alert alert-info mt-2" role="alert">';
        echo '<i class="fa fa-info-circle"></i> ';
        echo '<strong>' . get_string('remind_submit_test', 'block_chaside') . '</strong>';
        echo '</div>';
    }
    
    echo '<p class="mb-0"><em>' . get_string('results_available_when_complete', 'block_chaside', fullname($user)) . '</em></p>';
    echo '</div>';
    
    // Navigation buttons (match personality_test pattern)
    echo html_writer::start_div('mt-5 text-center d-flex gap-3 justify-content-center');
    if (has_capability('block/chaside:viewreports', $context)) {
        echo html_writer::link(
            new moodle_url('/blocks/chaside/admin_view.php', array('courseid' => $courseid)),
            '<i class="fa fa-arrow-left mr-2"></i>' . get_string('back_to_admin', 'block_chaside'),
            array('class' => 'btn btn-secondary btn-modern mr-3')
        );
    }
    echo html_writer::link(
        new moodle_url('/course/view.php', array('id' => $courseid)),
        '<i class="fa fa-home mr-2"></i>' . get_string('back_to_course', 'block_chaside'),
        array('class' => 'btn btn-modern', 'style' => 'background: linear-gradient(135deg, #ffd966 0%, #ffb600 100%); border: none; color: white;')
    );
    echo html_writer::end_div();
    echo '</div>';
    
    echo $OUTPUT->footer();
    exit;
}

// Generate official CHASIDE results
$facade = new \block_chaside\facade();
$response_array = (array) $response;

// Get user information
$user = $DB->get_record('user', array('id' => $userid));
$meta = array(
    'nombre' => fullname($user),
    'curso' => $course->shortname,
    'fecha_aplicacion' => date('Y-m-d', $response->timemodified),
    'version_instrumento' => 'CHASIDE v1.0'
);

$results = $facade->generate_results_json($response_array, $meta);

echo $OUTPUT->header();

$arealabels = array(
    'C' => get_string('area_c', 'block_chaside'),
    'H' => get_string('area_h', 'block_chaside'),
    'A' => get_string('area_a', 'block_chaside'),
    'S' => get_string('area_s', 'block_chaside'),
    'I' => get_string('area_i', 'block_chaside'),
    'D' => get_string('area_d', 'block_chaside'),
    'E' => get_string('area_e', 'block_chaside'),
);

// Extract a friendly name + (letter) from strings like "Administrative (C)".
$split_area_label = function(string $label): array {
    $label = trim($label);
    if (preg_match('/^(.*)\s*\(([CHASIDE])\)\s*$/u', $label, $m)) {
        return array(trim($m[1]), $m[2]);
    }
    return array($label, '');
};

echo html_writer::start_tag('div', array('class' => 'chaside-results-page'));
echo html_writer::start_tag('div', array('class' => 'container-fluid chaside-results-container'));

echo html_writer::start_tag('div', array('class' => 'chaside-results-hero mb-4'));
echo html_writer::tag('h2', $pagetitle, array('class' => 'chaside-page-title'));

// Mostrar información del usuario si es administrador viendo resultados de otro usuario
if ($userid != $USER->id) {
    echo html_writer::tag('div', get_string('completion_date_label', 'block_chaside') . ' ' . userdate($response->timemodified), array('class' => 'chaside-page-meta'));
}
echo html_writer::end_tag('div');

// Executive Summary Section
echo html_writer::start_tag('div', array('class' => 'chaside-executive-summary mb-4'));
echo html_writer::tag('h3', get_string('executive_summary', 'block_chaside'), array('class' => 'chaside-section-title'));

// Top areas display
echo html_writer::start_tag('div', array('class' => 'row mb-3'));

if ($results['resumen_ejecutivo']['top1']) {
    $top1 = $results['resumen_ejecutivo']['top1'];
    echo html_writer::start_tag('div', array('class' => 'col-md-6 mb-3'));
    echo html_writer::start_tag('div', array('class' => 'card chaside-card border-chaside-primary'));
    echo html_writer::start_tag('div', array('class' => 'card-header bg-chaside-primary text-white'));
    echo html_writer::tag('h4', '🥇 ' . get_string('your_top_area', 'block_chaside'), array('class' => 'mb-0'));
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', array('class' => 'card-body'));
    echo html_writer::tag('h5', $top1['label'], array('class' => 'card-title'));
    echo html_writer::tag('p', get_string('total_score', 'block_chaside') . ': ' . $top1['total'] . '/14 (' . $top1['pct_total'] . '%)', array('class' => 'card-text'));
    echo html_writer::tag('p', get_string('interests', 'block_chaside') . ': ' . $top1['i'] . '/10 | ' . get_string('aptitudes', 'block_chaside') . ': ' . $top1['a'] . '/4', array('class' => 'card-text small text-muted'));
    
    // Progress bar for top1
    echo html_writer::start_tag('div', array('class' => 'progress mb-2', 'style' => 'height: 20px;'));
    echo html_writer::tag('div', $top1['pct_total'] . '%', array(
        'class' => 'progress-bar bg-chaside-primary',
        'style' => 'width: ' . $top1['pct_total'] . '%;',
        'role' => 'progressbar'
    ));
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

if ($results['resumen_ejecutivo']['top2']) {
    $top2 = $results['resumen_ejecutivo']['top2'];
    echo html_writer::start_tag('div', array('class' => 'col-md-6 mb-3'));
    echo html_writer::start_tag('div', array('class' => 'card chaside-card border-chaside-secondary'));
    echo html_writer::start_tag('div', array('class' => 'card-header bg-chaside-secondary text-white'));
    echo html_writer::tag('h4', '🥈 ' . get_string('second_top_area', 'block_chaside'), array('class' => 'mb-0'));
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', array('class' => 'card-body'));
    echo html_writer::tag('h5', $top2['label'], array('class' => 'card-title'));
    echo html_writer::tag('p', get_string('total_score', 'block_chaside') . ': ' . $top2['total'] . '/14 (' . $top2['pct_total'] . '%)', array('class' => 'card-text'));
    echo html_writer::tag('p', get_string('interests', 'block_chaside') . ': ' . $top2['i'] . '/10 | ' . get_string('aptitudes', 'block_chaside') . ': ' . $top2['a'] . '/4', array('class' => 'card-text small text-muted'));
    
    // Progress bar for top2
    echo html_writer::start_tag('div', array('class' => 'progress mb-2', 'style' => 'height: 20px;'));
    echo html_writer::tag('div', $top2['pct_total'] . '%', array(
        'class' => 'progress-bar bg-chaside-secondary',
        'style' => 'width: ' . $top2['pct_total'] . '%;',
        'role' => 'progressbar'
    ));
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

echo html_writer::end_tag('div'); // row

// Gap alerts
if (!empty($results['resumen_ejecutivo']['alertas_brecha'])) {
    echo html_writer::start_tag('div', array('class' => 'chaside-gap-alerts mb-3'));
    echo html_writer::start_tag('div', array('class' => 'card chaside-card border-chaside-primary'));
    echo html_writer::start_tag('div', array('class' => 'card-header chaside-card-header-soft'));
    echo html_writer::tag('h5', '<i class="fa fa-exclamation-triangle mr-2"></i>' . get_string('gap_alerts', 'block_chaside'), array('class' => 'mb-0'));
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', array('class' => 'card-body'));
    echo html_writer::start_tag('div', array('class' => 'chaside-gap-grid'));
    foreach ($results['resumen_ejecutivo']['alertas_brecha'] as $alert) {
        $rawarea = isset($alert['area']) ? trim((string)$alert['area']) : '';
        $tipo = isset($alert['tipo']) ? s((string)$alert['tipo']) : '';

        $areacode = '';
        if (preg_match('/^[CHASIDE]$/u', $rawarea)) {
            $areacode = $rawarea;
        } elseif (preg_match('/\(([CHASIDE])\)\s*$/u', $rawarea, $m)) {
            $areacode = $m[1];
        }

        $label = $rawarea;
        if ($areacode !== '' && isset($arealabels[$areacode])) {
            $label = $arealabels[$areacode];
        }

        list($areaname, $arealetter) = $split_area_label((string)$label);
        $areaname = s($areaname);
        $arealetter = s($arealetter);

        if ($arealetter !== '') {
            $areaname .= ' (' . $arealetter . ')';
        }

        echo html_writer::start_tag('div', array('class' => 'chaside-gap-chip'));
        echo html_writer::tag('div', $areaname, array('class' => 'chaside-gap-chip-title'));
        $subtitle = trim($tipo);
        
        echo html_writer::tag('div', $subtitle, array('class' => 'chaside-gap-chip-subtitle'));
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

echo html_writer::end_tag('div'); // executive summary

// Detailed Results Table
echo html_writer::start_tag('div', array('class' => 'chaside-detailed-table mb-4'));
echo html_writer::tag('h3', get_string('detailed_table', 'block_chaside'), array('class' => 'chaside-section-title'));

echo html_writer::start_tag('div', array('class' => 'table-responsive'));
echo html_writer::start_tag('table', array('class' => 'table table-striped table-bordered'));
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('area_label', 'block_chaside'), array('scope' => 'col'));
echo html_writer::tag('th', get_string('interests', 'block_chaside') . ' (0-10)', array('scope' => 'col'));
echo html_writer::tag('th', get_string('aptitudes', 'block_chaside') . ' (0-4)', array('scope' => 'col'));
echo html_writer::tag('th', get_string('total', 'block_chaside') . ' (0-14)', array('scope' => 'col'));
echo html_writer::tag('th', get_string('level', 'block_chaside'), array('scope' => 'col'));
echo html_writer::tag('th', get_string('gap', 'block_chaside'), array('scope' => 'col'));
echo html_writer::tag('th', get_string('interpretation', 'block_chaside'), array('scope' => 'col'));
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');

echo html_writer::start_tag('tbody');

foreach ($results['tabla_principal'] as $row) {
    // Determine row styling based on top areas
    $row_class = '';
    $row_style = '';
    if ($results['resumen_ejecutivo']['top1'] && $row['area'] == $results['resumen_ejecutivo']['top1']['area']) {
        $row_class = 'table-chaside-primary';
        $row_style = 'border-left: 4px solid #ffb600;';
    } elseif ($results['resumen_ejecutivo']['top2'] && $row['area'] == $results['resumen_ejecutivo']['top2']['area']) {
        $row_class = 'table-chaside-secondary';
        $row_style = 'border-left: 4px solid #ffd966;';
    }
    
    echo html_writer::start_tag('tr', array('class' => $row_class, 'style' => $row_style));
    echo html_writer::tag('td', html_writer::tag('strong', $row['label']));
    echo html_writer::tag('td', $row['interes']['score'] . ' (' . $row['interes']['pct'] . '%)');
    echo html_writer::tag('td', $row['aptitud']['score'] . ' (' . $row['aptitud']['pct'] . '%)');
    echo html_writer::tag('td', html_writer::tag('strong', $row['total']['score'] . ' (' . $row['total']['pct'] . '%)'));
    
    // Level with badge
    $level_class = 'badge-secondary';
    switch ($row['nivel']) {
        case get_string('level_alto', 'block_chaside'):
            $level_class = 'badge-success';
            break;
        case get_string('level_medio', 'block_chaside'):
            $level_class = 'badge-primary';
            break;
        case get_string('level_emergente', 'block_chaside'):
            $level_class = 'badge-warning';
            break;
        case get_string('level_bajo', 'block_chaside'):
            $level_class = 'badge-secondary';
            break;
    }
    echo html_writer::tag('td', html_writer::tag('span', $row['nivel'], array('class' => 'badge ' . $level_class)));
    
    // Gap with badge
    $gap_class = 'badge-light';
    if ($row['brecha'] == get_string('gap_interest_higher', 'block_chaside')) {
        $gap_class = 'badge-info';
    } elseif ($row['brecha'] == get_string('gap_aptitude_higher', 'block_chaside')) {
        $gap_class = 'badge-success';
    }
    echo html_writer::tag('td', html_writer::tag('span', $row['brecha'], array('class' => 'badge ' . $gap_class)));
    
    echo html_writer::tag('td', html_writer::tag('small', $row['interpretacion_breve']));
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// Recommendations Section
echo html_writer::start_tag('div', array('class' => 'chaside-recommendations mb-4'));
echo html_writer::tag('h3', get_string('recommendations', 'block_chaside'), array('class' => 'chaside-section-title'));

$recommendations = !empty($results['recomendaciones']) ? $results['recomendaciones'] : array();

// De-duplicate recommendations to avoid repeated lines (can happen when multiple rules produce the same advice).
$recommendationsunique = array();
foreach ($recommendations as $recommendation) {
    $raw = (string)$recommendation;
    $normalized = preg_replace('/\s+/u', ' ', trim($raw));
    if ($normalized === '') {
        continue;
    }
    if (!array_key_exists($normalized, $recommendationsunique)) {
        $recommendationsunique[$normalized] = $raw;
    }
}
$recommendations = array_values($recommendationsunique);
$recommendationscount = count($recommendations);

if ($recommendationscount > 0) {
    echo html_writer::start_tag('div', array('class' => 'alert alert-info chaside-recommendations-list'));
    echo html_writer::start_tag('ul', array('class' => 'mb-0'));
    foreach ($recommendations as $recommendation) {
        echo html_writer::tag('li', format_text($recommendation, FORMAT_PLAIN));
    }
    echo html_writer::end_tag('ul');
    echo html_writer::end_tag('div');
}

echo html_writer::end_tag('div');

// Visual Chart Section (Simple bar chart with CSS)
echo html_writer::start_tag('div', array('class' => 'chaside-visual-chart mb-4'));
echo html_writer::tag('h3', get_string('scores_chart_title', 'block_chaside'), array('class' => 'chaside-section-title'));

$colors = array(
    'C' => '#FF6B6B',
    'H' => '#4ECDC4', 
    'A' => '#45B7D1',
    'S' => '#96CEB4',
    'I' => '#FFEAA7',
    'D' => '#DDA0DD',
    'E' => '#98D8C8'
);

foreach ($results['tabla_principal'] as $row) {
    $percentage = $row['total']['pct'];
    $color = $colors[$row['area']];
    
    echo html_writer::start_tag('div', array('class' => 'score-item mb-3'));
    echo html_writer::tag('label', $row['label'] . ': ' . $row['total']['score'] . '/14 (' . $percentage . '%)', array('class' => 'score-label'));
    echo html_writer::start_tag('div', array('class' => 'progress', 'style' => 'height: 25px;'));
    echo html_writer::tag('div', $percentage . '%', array(
        'class' => 'progress-bar text-dark',
        'role' => 'progressbar',
        'style' => "width: {$percentage}%; background-color: {$color};",
        'aria-valuenow' => $percentage,
        'aria-valuemin' => '0',
        'aria-valuemax' => '100'
    ));
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

echo html_writer::end_tag('div');

// Guidance Note
echo html_writer::start_tag('div', array('class' => 'chaside-guidance mb-4'));
echo html_writer::tag('h4', get_string('guidance_note', 'block_chaside'), array('class' => 'chaside-section-title'));
echo html_writer::tag('p', $results['apendice_opcional']['nota'], array('class' => 'mb-0'));
echo html_writer::end_tag('div');

// Navigation buttons (match personality_test pattern)
echo html_writer::start_div('mt-5 text-center d-flex gap-3 justify-content-center');
if (has_capability('block/chaside:viewreports', $context)) {
    echo html_writer::link(
        new moodle_url('/blocks/chaside/admin_view.php', array('courseid' => $courseid)),
        '<i class="fa fa-arrow-left mr-2"></i>' . get_string('back_to_admin', 'block_chaside'),
        array('class' => 'btn btn-secondary btn-modern mr-3')
    );
}
echo html_writer::link(
    new moodle_url('/course/view.php', array('id' => $courseid)),
    '<i class="fa fa-home mr-2"></i>' . get_string('back_to_course', 'block_chaside'),
    array('class' => 'btn btn-modern', 'style' => 'background: linear-gradient(135deg, #ffd966 0%, #ffb600 100%); border: none; color: white;')
);
echo html_writer::end_div();

// Close wrapper containers
echo html_writer::end_tag('div'); // .container-fluid
echo html_writer::end_tag('div'); // .chaside-results-page

echo $OUTPUT->footer();
