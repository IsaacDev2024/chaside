<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/moodleblock.class.php');

class block_chaside extends block_base {
    
    public function init() {
        $this->title = get_string('pluginname', 'block_chaside');
    }
    
    public function get_content() {
        global $USER, $COURSE, $OUTPUT, $DB, $CFG, $PAGE;
        
        if ($this->content !== null) {
            return $this->content;
        }
        
        // Load block styles
        $PAGE->requires->css('/blocks/chaside/styles.css');
        
        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';
        
        if (!isloggedin() || empty($COURSE->id)) {
            return $this->content;
        }

        $context = context_course::instance($COURSE->id);
        
        // Check if user can manage responses (teacher/admin)
        if (has_capability('block/chaside:manage_responses', $context)) {
            // Teacher/Admin view: enhanced management interface
            ob_start();
            $this->show_management_summary();
            $this->content->text = ob_get_clean();
        } else if (has_capability('block/chaside:take_test', $context)) {
            // Student view: check if test is completed (in any course)
            $response = $DB->get_record('block_chaside_responses', array(
                'userid' => $USER->id
            ));
            
            if ($response && $response->is_completed) {
                // Show results directly in the block
                ob_start();
                $this->show_student_results($response);
                $this->content->text = ob_get_clean();
            } else {
                // Show enhanced test invitation with progress if applicable
                ob_start();
                $this->show_test_invitation($response);
                $this->content->text = ob_get_clean();
            }
        }       
        return $this->content;
    }
    
    private function show_student_results($response) {
        global $COURSE, $USER;
        
        // Convert stdClass object to array for the facade
        $response_array = (array) $response;
        
        // Generate results using new official format
        $facade = new \block_chaside\facade();
        $meta = array(
            'nombre' => fullname($USER),
            'curso' => $COURSE->shortname,
            'fecha_aplicacion' => date('Y-m-d', $response->timemodified),
            'version_instrumento' => 'CHASIDE v1.0'
        );
        
        $results = $facade->generate_results_json($response_array, $meta);
        
        echo '<div class="chaside-results-block" style="padding: 15px; background: white; border-radius: 8px; border: 1px solid #dee2e6;">';
        
        // Header with success icon
        echo '<div class="chaside-header text-center mb-3">';
        echo '<div style="position: relative; display: inline-block; line-height: 0;">';
        echo $this->get_chaside_icon('4em', 'display: block;', false);
        echo '<i class="fa fa-check" style="position: absolute; top: -6px; right: -9px; font-size: 1.4em; background: white; border-radius: 50%; line-height: 1;"></i>';
        echo '</div>';
        echo '<h6 class="mt-2 mb-1 font-weight-bold">' . get_string('test_completed', 'block_chaside') . '</h6>';
        echo '<small class="text-muted">' . get_string('your_orientation_results', 'block_chaside') . '</small>';
        echo '</div>';
        
        // Test description
        echo '<div class="chaside-description mb-3" style="background: #f8f9fa; padding: 10px 12px; border-radius: 5px; border-left: 3px solid #ffb600;">';
        echo '<small class="text-muted" style="line-height: 1.5;">';
        echo '<i class="fa fa-info-circle" style="color: #ffb600;"></i> ';
        echo get_string('chaside_description', 'block_chaside');
        echo '</small>';
        echo '</div>';
        
        // Executive Summary
        echo '<div class="chaside-executive-summary mb-3">';
        echo '<h6 class="mb-2 font-weight-bold">' . get_string('executive_summary', 'block_chaside') . '</h6>';
        
        // Top areas
        if ($results['resumen_ejecutivo']['top1']) {
            $top1 = $results['resumen_ejecutivo']['top1'];
            echo '<div class="card border-primary mb-2" style="border-left: 4px solid #ffb600 !important; border-color: #ffb600 !important;">';
            echo '<div class="card-body p-2">';
            echo '<div class="d-flex justify-content-between align-items-center">';
            echo '<div>';
            echo '<strong style="word-wrap: break-word; overflow-wrap: break-word; hyphens: auto; display: block;">1. ' . $top1['label'] . '</strong>';
            echo '<small class="text-muted">' . $top1['pct_total'] . '% (' . $top1['total'] . '/14)</small>';
            echo '</div>';
            echo '<div class="text-right">';
            echo '<small>I:' . $top1['i'] . ' A:' . $top1['a'] . '</small>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        
        if ($results['resumen_ejecutivo']['top2']) {
            $top2 = $results['resumen_ejecutivo']['top2'];
            echo '<div class="card border-secondary mb-2" style="border-color: #6c757d !important;">';
            echo '<div class="card-body p-2">';
            echo '<div class="d-flex justify-content-between align-items-center">';
            echo '<div>';
            echo '<strong style="word-wrap: break-word; overflow-wrap: break-word; hyphens: auto; display: block;">2. ' . $top2['label'] . '</strong>';
            echo '<small class="text-muted">' . $top2['pct_total'] . '% (' . $top2['total'] . '/14)</small>';
            echo '</div>';
            echo '<div class="text-right">';
            echo '<small>I:' . $top2['i'] . ' A:' . $top2['a'] . '</small>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        
        // Gap alerts (if any)
        if (!empty($results['resumen_ejecutivo']['alertas_brecha'])) {
            echo '<div class="chaside-gap-alerts mb-3">';
            echo '<h6 class="mb-2 font-weight-bold">' . get_string('gap_alerts', 'block_chaside') . '</h6>';
            foreach ($results['resumen_ejecutivo']['alertas_brecha'] as $alert) {
                $badge_class = 'badge-warning';
                if ($alert['tipo'] == get_string('gap_interest_higher', 'block_chaside')) {
                    $badge_class = 'badge-info';
                } elseif ($alert['tipo'] == get_string('gap_aptitude_higher', 'block_chaside')) {
                    $badge_class = 'badge-success';
                }
                echo '<span class="badge ' . $badge_class . ' mr-1">' . $alert['area'] . ': ' . $alert['tipo'] . '</span>';
            }
            echo '</div>';
        }
        
        // Quick recommendations
        echo '<div class="chaside-recommendations mb-3">';
        echo '<h6 class="mb-2 font-weight-bold">' . get_string('recommendations', 'block_chaside') . '</h6>';
        echo '<ul class="list-unstyled">';

        // Deduplicate recommendations (normalize whitespace) and keep order
        $rawrecs = !empty($results['recomendaciones']) ? (array)$results['recomendaciones'] : array();
        $recommendationsunique = array();
        foreach ($rawrecs as $recommendation) {
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

        // Show only first 2 not duplicated recommendations
        $rec_count = 0;
        foreach ($recommendations as $recommendation) {
            if ($rec_count >= 2) break;
            echo '<li class="small mb-1"><i class="fa fa-arrow-right" style="color: #ffb600;"></i> ' . $recommendation . '</li>';
            $rec_count++;
        }

        echo '</ul>';
        echo '</div>';
        
        // Action buttons
        echo '<div class="chaside-actions text-center">';
        $url = new moodle_url('/blocks/chaside/view_results.php', array(
            'courseid' => $COURSE->id
        ));
        echo '<a href="' . $url . '" class="btn btn-sm" style="background: linear-gradient(135deg, #ffb600 0%, #e6a300 100%); border-color: #ffb600; color: #fff;">';
        echo '<i class="fa fa-chart-bar"></i> ' . get_string('view_detailed_results', 'block_chaside');
        echo '</a>';
        echo '</div>';
        
        echo '</div>';

    }
    
    /**
     * Helper method to generate chaside icon HTML (SVG)
     * @param string $size Icon size (default: 1.8em)
     * @param string $additional_style Additional inline styles
     * @param bool $centered Whether to center the icon
     * @return string HTML img tag with the SVG icon
     */
    private function get_chaside_icon($size = '1.8em', $additional_style = '', $centered = false) {
        $iconurl = new moodle_url('/blocks/chaside/pix/chaside_icon.svg');
        $style = 'width: ' . $size . '; height: ' . $size . '; vertical-align: middle; float: none !important;';
        if ($centered) {
            $style .= ' display: block; margin: 0 auto;';
        }
        if (!empty($additional_style)) {
            $style .= ' ' . $additional_style;
        }
        return '<img class="chaside-icon" src="' . $iconurl . '" alt="CHASIDE Icon" style="' . $style . '" />';
    }
    
    private function show_test_invitation($response) {
        global $COURSE;
        
        echo '<div class="chaside-invitation-block">';
        
        // Header with CHASIDE icon
        echo '<div class="chaside-header text-center mb-3">';
        echo $this->get_chaside_icon('4em', '', true);
        echo '<h6 class="mt-2 mb-1 font-weight-bold">' . get_string('vocational_orientation', 'block_chaside') . '</h6>';
        echo '<small class="text-muted">' . get_string('discover_your_interests', 'block_chaside') . '</small>';
        echo '</div>';
        
        if ($response) {
            // Show progress for started test
            $answered_count = 0;
            for ($i = 1; $i <= 98; $i++) {
                if (isset($response->{"q{$i}"}) && $response->{"q{$i}"} !== null) {
                    $answered_count++;
                }
            }
            $progress_percentage = ($answered_count / 98) * 100;
            
            // Check if all questions are answered but test not completed
            $all_answered = ($answered_count == 98);
            
            // Always show test description
            echo '<div class="chaside-description mb-3" style="background: white; padding: 10px 12px; border-radius: 5px; border-left: 3px solid #ffb600;">';
            echo '<small class="text-muted" style="line-height: 1.5;">';
            echo '<i class="fa fa-info-circle" style="color: #ffb600;"></i> ';
            echo get_string('chaside_description', 'block_chaside');
            echo '</small>';
            echo '</div>';
            
            if ($all_answered) {
                // Show special message when all questions answered (same style as personality_test)
                echo '<div class="alert alert-warning mb-3" style="padding: 12px 15px; margin-bottom: 15px; border-left: 4px solid #ffc107; background-color: #fff3cd; border-radius: 4px;">';
                echo '<div style="display: flex; align-items: start;">';
                echo '<i class="fa fa-exclamation-triangle" style="color: #856404; margin-right: 10px; margin-top: 2px; font-size: 1.2em;"></i>';
                echo '<div>';
                echo '<strong style="color: #856404;">' . get_string('all_answered_title', 'block_chaside') . '</strong><br>';
                echo '<small style="color: #856404;">' . get_string('all_answered_message', 'block_chaside') . '</small>';
                echo '</div>';
                echo '</div>';
                echo '</div>';
                
                $button_text = get_string('finish_test_now', 'block_chaside');
                $button_icon = 'fa-flag-checkered';
                $button_class = 'btn-success';
                $scroll_to_finish = true;
            } else {
                $button_text = get_string('continue_test', 'block_chaside');
                $button_icon = 'fa-play';
                $button_class = 'btn-primary';
                $scroll_to_finish = false;
                
                // Find first unanswered question for scroll parameter
                $first_unanswered = null;
                for ($i = 1; $i <= 98; $i++) {
                    if (!isset($response->{"q{$i}"}) || $response->{"q{$i}"} === null) {
                        $first_unanswered = $i;
                        break;
                    }
                }
            }
            
            // Show progress bar with block colors (always shown when in progress)
            echo '<div class="chaside-progress mb-3">';
            echo '<div class="d-flex justify-content-between align-items-center mb-2">';
            echo '<span class="small font-weight-bold">' . get_string('your_progress', 'block_chaside') . '</span>';
            echo '<span class="small text-muted">' . $answered_count . '/98</span>';
            echo '</div>';
            echo '<div class="progress mb-2" style="height: 8px; background-color: #fffbf0;">';
            echo '<div class="progress-bar" style="width: ' . $progress_percentage . '%; background: linear-gradient(90deg, #ffd966 0%, #ffb600 100%);"></div>';
            echo '</div>';
            echo '<small class="text-muted">' . number_format($progress_percentage, 1) . '% ' . get_string('completed_status', 'block_chaside') . '</small>';
            echo '</div>';
        } else {
            // Show test description for new test
            echo '<div class="chaside-description mb-3">';
            echo '<div class="card border-info">';
            echo '<div class="card-body p-3">';
            echo '<h6 class="card-title font-weight-bold">';
            echo '<i class="fa fa-info-circle text-info"></i> ';
            echo get_string('what_is_chaside', 'block_chaside');
            echo '</h6>';
            echo '<p class="card-text small mb-2">' . get_string('chaside_description', 'block_chaside') . '</p>';
            echo '<ul class="list-unstyled small mb-0">';
            echo '<li><i class="fa fa-check text-success"></i> ' . get_string('feature_98_questions', 'block_chaside') . '</li>';
            echo '<li><i class="fa fa-check text-success"></i> ' . get_string('feature_7_areas', 'block_chaside') . '</li>';
            echo '<li><i class="fa fa-check text-success"></i> ' . get_string('feature_instant_results', 'block_chaside') . '</li>';
            echo '</ul>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            
            $button_text = get_string('start_test', 'block_chaside');
            $button_icon = 'fa-rocket';
            $button_class = 'btn-primary';
        }
        
        // Call to action
        echo '<div class="chaside-actions text-center">';
        
        // Change button text, icon and URL based on completion status
        if (isset($all_answered) && $all_answered) {
            // All answered - go to last page with scroll_to_finish flag
            $questions_per_page = 10;
            $total_questions = 98;
            $last_page = (int)ceil($total_questions / $questions_per_page);
            
            $url = new moodle_url('/blocks/chaside/view.php', array(
                'courseid' => $COURSE->id,
                'page' => $last_page,
                'scroll_to_finish' => 1
            ));
        } else {
            // Test in progress - let view.php calculate the correct page automatically
            $url = new moodle_url('/blocks/chaside/view.php', array(
                'courseid' => $COURSE->id
            ));
        }
        
        // Button styling based on state
        $button_style = '';
        if ($button_class == 'btn-primary') {
            $button_style = 'background: linear-gradient(135deg, #ffb600 0%, #e6a300 100%); border-color: #ffb600;';
        } else if ($button_class == 'btn-success') {
            $button_style = 'background: #28a745; border-color: #28a745;';
        }
        
        echo '<a href="' . $url . '" class="btn ' . $button_class . ' btn-block" style="' . $button_style . '">';
        echo '<i class="fa ' . $button_icon . '"></i> ' . $button_text;
        echo '</a>';
        echo '</div>';
        
        echo '</div>';
    }
    
    public function applicable_formats() {
        return array('course' => true, 'my' => true);
    }
    
    public function has_config() {
        return false;
    }
    
    private function show_management_summary() {
        global $COURSE, $DB;
        
        // Get statistics
        $context = context_course::instance($COURSE->id);
        $enrolled_students = get_enrolled_users($context, 'block/chaside:take_test');
        
        // Get responses only for enrolled students in this course.
        // Defensive: exclude any teacher/manager-type user even if misconfigured.
        $enrolled_ids = array_keys($enrolled_students);
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
        $enrolled_ids = $student_ids;
        $total_enrolled = count($enrolled_ids);
        $responses = array();
        $completed_responses = array();
        $total_completed = 0;
        $total_in_progress = 0;
        
        if (!empty($enrolled_ids)) {
            list($insql, $params) = $DB->get_in_or_equal($enrolled_ids, SQL_PARAMS_NAMED, 'user');
            $sql = "SELECT * FROM {block_chaside_responses} WHERE userid $insql";
            $responses = $DB->get_records_sql($sql, $params);
            
            $completed_responses = array_filter($responses, function($r) { return $r->is_completed; });
            $total_completed = count($completed_responses);
            $total_in_progress = count($responses) - $total_completed;
        }
        
        $completion_rate = $total_enrolled > 0 ? ($total_completed / $total_enrolled) * 100 : 0;
        
        echo '<div class="chaside-management-block">';
        
        // Header
        echo '<div class="chaside-header text-center mb-3">';
        echo $this->get_chaside_icon('4em', '', true);
        echo '<h6 class="mt-2 mb-1 font-weight-bold">' . get_string('management_title', 'block_chaside') . '</h6>';
        echo '<small class="text-muted">' . get_string('course_overview', 'block_chaside') . '</small>';
        echo '</div>';
        
        // Quick stats
        echo '<div class="chaside-stats mb-3">';
        echo '<div class="row text-center">';
        
        // Completion rate
        echo '<div class="col-4">';
        echo '<div class="stat-card">';
        echo '<div class="stat-number text-success">' . number_format($completion_rate, 1) . '%</div>';
        echo '<div class="stat-label">' . get_string('completion_rate', 'block_chaside') . '</div>';
        echo '</div>';
        echo '</div>';
        
        // Completed tests
        echo '<div class="col-4">';
        echo '<div class="stat-card">';
        echo '<div class="stat-number" style="color: #ffb600;">' . $total_completed . '</div>';
        echo '<div class="stat-label">' . get_string('completed', 'block_chaside') . '</div>';
        echo '</div>';
        echo '</div>';
        
        // In progress
        echo '<div class="col-4">';
        echo '<div class="stat-card">';
        echo '<div class="stat-number text-warning">' . $total_in_progress . '</div>';
        echo '<div class="stat-label">' . get_string('in_progress', 'block_chaside') . '</div>';
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
        
        // Progress bar
        echo '<div class="chaside-progress-overview mb-3">';
        echo '<div class="progress" style="height: 10px;">';
        echo '<div class="progress-bar" style="width: ' . ($completion_rate) . '%; background: linear-gradient(135deg, #ffb600 0%, #e6a300 100%);"></div>';
        echo '</div>';
        echo '<small class="text-muted">' . $total_completed . ' ' . get_string('of', 'block_chaside') . ' ' . $total_enrolled . ' ' . get_string('students_completed', 'block_chaside') . '</small>';
        echo '</div>';
        
        // Recent activity (if any)
        if ($total_completed > 0 && !empty($enrolled_ids)) {
            list($insql, $params) = $DB->get_in_or_equal($enrolled_ids, SQL_PARAMS_NAMED, 'user');
            $params['completed'] = 1;
            
            $sql = "SELECT * FROM {block_chaside_responses} 
                    WHERE userid $insql AND is_completed = :completed 
                    ORDER BY timemodified DESC";
            $recent_responses = $DB->get_records_sql($sql, $params, 0, 3);
                
            echo '<div class="chaside-recent mb-3">';
            echo '<h6 class="mb-2 font-weight-bold">' . get_string('recent_completions', 'block_chaside') . '</h6>';
            foreach ($recent_responses as $response) {
                $user = $DB->get_record('user', array('id' => $response->userid));
                echo '<div class="d-flex justify-content-between align-items-center mb-1">';
                echo '<span class="small">' . fullname($user) . '</span>';
                echo '<span class="badge small" style="background: linear-gradient(135deg, #ffb600 0%, #e6a300 100%); color: #fff;">' . userdate($response->timemodified, get_string('strftimedatefullshort')) . '</span>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        // Management actions
        $url = new moodle_url('/blocks/chaside/admin_view.php', ['courseid' => $this->page->course->id]);
        echo '<div class="chaside-actions text-center mt-3">';
        echo '<a href="' . $url . '" class="btn btn-sm btn-block" style="background: linear-gradient(135deg, #ffb600 0%, #e6a300 100%); border-color: #ffb600; color: #fff;">';
        echo '<i class="fa fa-chart-bar"></i> ' . get_string('admin_dashboard_invitation', 'block_chaside');
        echo '</a>';
        echo '</div>';
        echo '</div>';
    }
}
