<?php
/**
 * View Results - CHASIDE Block
 *
 * @package    block_chaside
 * @copyright  2026 SAVIO - Sistema de Aprendizaje Virtual Interactivo (UTB)
 * @author     SAVIO Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

define('BLOCK_CHASIDE_QUESTIONS_PER_PAGE', 10);
define('BLOCK_CHASIDE_TOTAL_QUESTIONS', 98);

$courseid = required_param('courseid', PARAM_INT);
$userid = optional_param('userid', $USER->id, PARAM_INT);

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);

// Check if the block is added to the course
if (!$DB->record_exists('block_instances', array('blockname' => 'chaside', 'parentcontextid' => $context->id))) {
    redirect(new moodle_url('/course/view.php', array('id' => $courseid)));
}

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

// Get user information ONCE
$user = $DB->get_record('user', array('id' => $userid)); 

$pagetitle = ($userid != $USER->id)
    ? get_string('viewing_results_of', 'block_chaside', fullname($user))
    : get_string('your_results', 'block_chaside');
$PAGE->set_title($pagetitle);

$template_data = [
    'title' => $pagetitle,
    'course_url' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
    'admin_url' => (new moodle_url('/blocks/chaside/admin_view.php', ['courseid' => $courseid]))->out(false),
    'can_view_reports' => has_capability('block/chaside:viewreports', $context),
    'str_back_to_course' => get_string('back_to_course', 'block_chaside'),
    'str_back_to_admin' => get_string('back_to_admin', 'block_chaside'),
];

// Check if test is in progress
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
    
    $template_data['is_completed'] = false;
    $template_data['str_test_in_progress'] = get_string('test_in_progress', 'block_chaside');
    $template_data['str_test_in_progress_message'] = get_string('test_in_progress_message', 'block_chaside', fullname($user));
    $template_data['str_progress_label'] = get_string('progress_label', 'block_chaside');
    $template_data['progress_percentage'] = $progress_percentage;
    $template_data['str_has_answered'] = get_string('has_answered', 'block_chaside');
    $template_data['answered'] = $answered;
    $template_data['total_questions'] = BLOCK_CHASIDE_TOTAL_QUESTIONS;
    $template_data['str_questions'] = get_string('questions', 'block_chaside');
    $template_data['all_answered'] = ($answered == BLOCK_CHASIDE_TOTAL_QUESTIONS);
    $template_data['str_remind_submit_test'] = get_string('remind_submit_test', 'block_chaside');
    $template_data['str_results_available_when_complete'] = get_string('results_available_when_complete', 'block_chaside', fullname($user));
    
    echo $OUTPUT->render_from_template('block_chaside/view_results', $template_data);
    echo $OUTPUT->footer();
    exit;
}

// Generate official CHASIDE results
$facade = new \block_chaside\facade();
$response_array = (array) $response;

// Meta info
$meta = array(
    'nombre' => fullname($user),
    'curso' => $course->shortname,
    'fecha_aplicacion' => date('Y-m-d', $response->timemodified),
    'version_instrumento' => 'CHASIDE v1.0'
);

$results = $facade->generate_results_json($response_array, $meta);

echo $OUTPUT->header();

$template_data['is_completed'] = true;
$template_data['meta'] = [
    'completion_date_label' => ($userid != $USER->id) ? get_string('completion_date_label', 'block_chaside') : '',
    'fecha_aplicacion' => ($userid != $USER->id) ? userdate($response->timemodified) : ''
];

// Area labels mapping for helper function
$arealabels = array(
    'C' => get_string('area_c', 'block_chaside'),
    'H' => get_string('area_h', 'block_chaside'),
    'A' => get_string('area_a', 'block_chaside'),
    'S' => get_string('area_s', 'block_chaside'),
    'I' => get_string('area_i', 'block_chaside'),
    'D' => get_string('area_d', 'block_chaside'),
    'E' => get_string('area_e', 'block_chaside'),
);

$split_area_label = function(string $label): array {
    $label = trim($label);
    if (preg_match('/^(.*)\s*\(([CHASIDE])\)\s*$/u', $label, $m)) {
        return array(trim($m[1]), $m[2]);
    }
    return array($label, '');
};

// Executive Summary Data
$template_data['str_executive_summary'] = get_string('executive_summary', 'block_chaside');
$template_data['str_your_top_area'] = get_string('your_top_area', 'block_chaside');
$template_data['str_second_top_area'] = get_string('second_top_area', 'block_chaside');
$template_data['str_total_score'] = get_string('total_score', 'block_chaside');
$template_data['str_interests'] = get_string('interests', 'block_chaside');
$template_data['str_aptitudes'] = get_string('aptitudes', 'block_chaside');

if ($results['resumen_ejecutivo']['top1']) {
    $template_data['top1'] = $results['resumen_ejecutivo']['top1'];
}
if ($results['resumen_ejecutivo']['top2']) {
    $template_data['top2'] = $results['resumen_ejecutivo']['top2'];
}

// Gap Alerts
$template_data['str_gap_alerts'] = get_string('gap_alerts', 'block_chaside');
$gap_alerts_data = [];
if (!empty($results['resumen_ejecutivo']['alertas_brecha'])) {
    foreach ($results['resumen_ejecutivo']['alertas_brecha'] as $alert) {
        $rawarea = isset($alert['area']) ? trim((string)$alert['area']) : '';
        
        // Resolve label logic (mirrored from original PHP)
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
        
        $areaname_final = $areaname;
        if ($arealetter !== '') {
            $areaname_final .= ' (' . $arealetter . ')';
        }
        
        $gap_alerts_data[] = [
            'area_name' => $areaname_final,
            'tipo' => $alert['tipo']
        ];
    }
}
$template_data['has_gap_alerts'] = !empty($gap_alerts_data);
$template_data['gap_alerts'] = $gap_alerts_data;

// Main Table Data
$template_data['str_detailed_table'] = get_string('detailed_table', 'block_chaside');
$template_data['str_area_label'] = get_string('area_label', 'block_chaside');
$template_data['str_total'] = get_string('total', 'block_chaside');
$template_data['str_level'] = get_string('level', 'block_chaside');
$template_data['str_gap'] = get_string('gap', 'block_chaside');
$template_data['str_interpretation'] = get_string('interpretation', 'block_chaside');

$rows = [];
foreach ($results['tabla_principal'] as $row) {
    // Row styling
    $row_class = '';
    $row_style = '';
    if ($results['resumen_ejecutivo']['top1'] && $row['area'] == $results['resumen_ejecutivo']['top1']['area']) {
        $row_class = 'table-chaside-primary';
        $row_style = 'border-left: 4px solid #ffb600;';
    } elseif ($results['resumen_ejecutivo']['top2'] && $row['area'] == $results['resumen_ejecutivo']['top2']['area']) {
        $row_class = 'table-chaside-secondary';
        $row_style = 'border-left: 4px solid #ffd966;';
    }

    // Badge styling helpers
    $level_class = 'badge-secondary';
    if ($row['nivel'] == get_string('level_alto', 'block_chaside')) $level_class = 'badge-success';
    elseif ($row['nivel'] == get_string('level_medio', 'block_chaside')) $level_class = 'badge-primary';
    elseif ($row['nivel'] == get_string('level_emergente', 'block_chaside')) $level_class = 'badge-warning';
    
    $gap_class = 'badge-light';
    if ($row['brecha'] == get_string('gap_interest_higher', 'block_chaside')) $gap_class = 'badge-info';
    elseif ($row['brecha'] == get_string('gap_aptitude_higher', 'block_chaside')) $gap_class = 'badge-success';

    $row['row_class'] = $row_class;
    $row['row_style'] = $row_style;
    $row['level_class'] = $level_class;
    $row['gap_class'] = $gap_class;
    $rows[] = $row;
}
$template_data['tabla_principal'] = $rows;

// Recommendations
$template_data['str_recommendations'] = get_string('recommendations', 'block_chaside');
$recommendations = !empty($results['recomendaciones']) ? $results['recomendaciones'] : array();
// De-duplication logic
$recommendationsunique = array();
foreach ($recommendations as $recommendation) {
    $raw = (string)$recommendation;
    $normalized = preg_replace('/\s+/u', ' ', trim($raw));
    if ($normalized === '') continue;
    if (!array_key_exists($normalized, $recommendationsunique)) {
        $recommendationsunique[$normalized] = $raw;
    }
}
$recommendations = array_values($recommendationsunique);
$template_data['has_recommendations'] = (count($recommendations) > 0);
$template_data['recomendaciones'] = $recommendations; // Mustache iterates strings easily

// Chart Data
$template_data['str_scores_chart_title'] = get_string('scores_chart_title', 'block_chaside');
$colors = array(
    'C' => '#FF6B6B', 'H' => '#4ECDC4', 'A' => '#45B7D1',
    'S' => '#96CEB4', 'I' => '#FFEAA7', 'D' => '#DDA0DD', 'E' => '#98D8C8'
);
$chart_data = [];
foreach ($results['tabla_principal'] as $row) {
    $chart_data[] = [
        'label' => $row['label'],
        'score' => $row['total']['score'],
        'pct' => $row['total']['pct'],
        'color' => $colors[$row['area']]
    ];
}
$template_data['chart_data'] = $chart_data;

// Guidance Note
$template_data['str_guidance_note'] = get_string('guidance_note', 'block_chaside');
$template_data['note'] = $results['apendice_opcional']['nota'];

echo $OUTPUT->render_from_template('block_chaside/view_results', $template_data);
echo $OUTPUT->footer();
