<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Helper function to get completed responses for a course.
 *
 * @param int $courseid The course ID.
 * @param int $groupid Optional group ID.
 * @return array|false Array containing [$course, $responses] or false if permission denied/error.
 */
function block_chaside_get_completed_responses($courseid, $groupid = 0) {
    global $DB;
    
    $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
    $context = context_course::instance($course->id);
    
    require_login($course, false);
    
    // Check permissions
    if (!has_capability('block/chaside:viewreports', $context) && !has_capability('block/chaside:manage_responses', $context) && !is_siteadmin()) {
        return false;
    }
    
    $student_ids = block_chaside_get_student_ids($context, $groupid);
    
    $responses = array();
    if (!empty($student_ids)) {
        list($insql, $params) = $DB->get_in_or_equal($student_ids, SQL_PARAMS_NAMED, 'user');
        $params['completed'] = 1;
        
        $responses = $DB->get_records_sql("
            SELECT cr.*, u.firstname, u.lastname, u.email, u.idnumber
            FROM {block_chaside_responses} cr
            JOIN {user} u ON cr.userid = u.id
            WHERE cr.userid $insql AND cr.is_completed = :completed
            ORDER BY cr.timemodified DESC
        ", $params);
    }
    
    return [$course, $responses];
}

/**
 * Helper function to get student IDs for a course context.
 *
 * @param context $context The course context.
 * @param int $groupid Optional group ID.
 * @return array Array of student user IDs.
 */
function block_chaside_get_student_ids($context, $groupid = 0) {
    // Get enrolled students
    $enrolled_users = get_enrolled_users($context, 'block/chaside:take_test', $groupid, 'u.id');
    $enrolled_ids = array_keys($enrolled_users);

    // Defensive: exclude any teacher/manager-type user even if misconfigured.
    $student_ids = array();
    foreach ($enrolled_ids as $candidateid) {
        $candidateid = (int)$candidateid;
        if (is_siteadmin($candidateid)) {
            continue;
        }
        if (has_capability('block/chaside:viewreports', $context, $candidateid) || has_capability('block/chaside:manage_responses', $context, $candidateid)) {
            continue;
        }
        $student_ids[] = $candidateid;
    }
    return $student_ids;
}

/**
 * Helper function to prepare export data for a single response.
 *
 * @param stdClass $response The response object joined with user data.
 * @param \block_chaside\facade $facade The facade instance.
 * @return array The formatted data row for export.
 */
function block_chaside_prepare_export_row($response, $facade) {
    // Convert stdClass to array for the facade
    $response_array = (array) $response;
    
    try {
        $scores = $facade->calculate_scores($response_array);
        $detailed_scores = $facade->calculate_detailed_scores($response_array);
        $top_areas = $facade->get_top_areas_v2($detailed_scores, 3);
    } catch (Exception $e) {
        // If calculation fails, use empty arrays
        $scores = array('C' => '', 'H' => '', 'A' => '', 'S' => '', 'I' => '', 'D' => '', 'E' => '');
        $detailed_scores = array('C' => array('interes_score' => '', 'aptitud_score' => ''), 'H' => array('interes_score' => '', 'aptitud_score' => ''), 'A' => array('interes_score' => '', 'aptitud_score' => ''), 'S' => array('interes_score' => '', 'aptitud_score' => ''), 'I' => array('interes_score' => '', 'aptitud_score' => ''), 'D' => array('interes_score' => '', 'aptitud_score' => ''), 'E' => array('interes_score' => '', 'aptitud_score' => ''));
        $top_areas = array();
    }
    
    // Safely access score keys with fallback
    $get_score = function($key) use ($scores) {
        return isset($scores[$key]) ? $scores[$key] : '';
    };
    
    $get_detailed = function($area, $type) use ($detailed_scores) {
        return isset($detailed_scores[$area][$type]) ? $detailed_scores[$area][$type] : '';
    };
    
    return array(
        'student_id' => $response->idnumber,
        'student_name' => $response->firstname . ' ' . $response->lastname,
        'student_email' => $response->email,
        'completion_date' => date('Y-m-d H:i:s', $response->timemodified),
        'administrative_score' => $get_score('C'),
        'administrative_interests' => $get_detailed('C', 'interes_score'),
        'administrative_aptitudes' => $get_detailed('C', 'aptitud_score'),
        'humanities_score' => $get_score('H'),
        'humanities_interests' => $get_detailed('H', 'interes_score'),
        'humanities_aptitudes' => $get_detailed('H', 'aptitud_score'),
        'artistic_score' => $get_score('A'),
        'artistic_interests' => $get_detailed('A', 'interes_score'),
        'artistic_aptitudes' => $get_detailed('A', 'aptitud_score'),
        'health_sciences_score' => $get_score('S'),
        'health_sciences_interests' => $get_detailed('S', 'interes_score'),
        'health_sciences_aptitudes' => $get_detailed('S', 'aptitud_score'),
        'technical_score' => $get_score('I'),
        'technical_interests' => $get_detailed('I', 'interes_score'),
        'technical_aptitudes' => $get_detailed('I', 'aptitud_score'),
        'defense_security_score' => $get_score('D'),
        'defense_security_interests' => $get_detailed('D', 'interes_score'),
        'defense_security_aptitudes' => $get_detailed('D', 'aptitud_score'),
        'experimental_sciences_score' => $get_score('E'),
        'experimental_sciences_interests' => $get_detailed('E', 'interes_score'),
        'experimental_sciences_aptitudes' => $get_detailed('E', 'aptitud_score'),
        'top_area_1' => isset($top_areas[0]) ? $top_areas[0]['area'] : '',
        'top_area_2' => isset($top_areas[1]) ? $top_areas[1]['area'] : '',
        'top_area_3' => isset($top_areas[2]) ? $top_areas[2]['area'] : ''
    );
}

/**
 * Helper function to get CSV headers for export.
 *
 * @return array Array of translated headers.
 */
function block_chaside_get_export_headers() {
    return array(
        get_string('export_student_id', 'block_chaside'),
        get_string('export_student_name', 'block_chaside'),
        get_string('export_student_email', 'block_chaside'),
        get_string('export_completion_date', 'block_chaside'),
        get_string('export_administrative_score', 'block_chaside'),
        get_string('export_administrative_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_administrative_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_humanities_score', 'block_chaside'),
        get_string('export_humanities_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_humanities_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_artistic_score', 'block_chaside'),
        get_string('export_artistic_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_artistic_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_health_sciences_score', 'block_chaside'),
        get_string('export_health_sciences_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_health_sciences_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_technical_score', 'block_chaside'),
        get_string('export_technical_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_technical_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_defense_security_score', 'block_chaside'),
        get_string('export_defense_security_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_defense_security_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_experimental_sciences_score', 'block_chaside'),
        get_string('export_experimental_sciences_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_experimental_sciences_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_top_area', 'block_chaside') . ' 1',
        get_string('export_top_area', 'block_chaside') . ' 2', 
        get_string('export_top_area', 'block_chaside') . ' 3'
    );
}
