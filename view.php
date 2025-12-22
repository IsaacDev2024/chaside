<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');

define('BLOCK_CHASIDE_QUESTIONS_PER_PAGE', 10);
define('BLOCK_CHASIDE_TOTAL_QUESTIONS', 98);

$courseid = required_param('courseid', PARAM_INT);
$page = optional_param('page', 1, PARAM_INT);
$scroll_to_finish = optional_param('scroll_to_finish', 0, PARAM_INT);

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('block/chaside:take_test', $context);

// Redirect teachers/admins to admin page
if (has_capability('block/chaside:manage_responses', $context)) {
    $manage_url = new moodle_url('/blocks/chaside/admin_view.php', array('courseid' => $courseid));
    redirect($manage_url, get_string('teachers_redirect_message', 'block_chaside'));
}

// NOTE: We intentionally do not accept a GET "scroll" parameter.
// Scrolling/highlighting is controlled internally via $SESSION.

$PAGE->set_url('/blocks/chaside/view.php', array('courseid' => $courseid, 'page' => $page));
$PAGE->set_title(get_string('test_title', 'block_chaside'));
$PAGE->set_heading($course->fullname);
$PAGE->set_context($context);
$PAGE->requires->css('/blocks/chaside/styles.css');

// Inicializar el facade
$facade = new \block_chaside\facade();

// Verificar si ya existe una respuesta del usuario (en cualquier curso)
$existing_response = $DB->get_record('block_chaside_responses', array(
    'userid' => $USER->id
));

// Pagination settings
$questions_per_page = BLOCK_CHASIDE_QUESTIONS_PER_PAGE;
$total_questions = BLOCK_CHASIDE_TOTAL_QUESTIONS;
$total_pages = ceil($total_questions / $questions_per_page);

// SECURITY: Validate that user cannot skip pages without completing previous ones
if ($existing_response && $page > 1) {
    // Check all questions from page 1 to current page - 1
    $max_allowed_page = 1;
    
    for ($p = 1; $p < $page; $p++) {
        $page_start = ($p - 1) * $questions_per_page + 1;
        $page_end = min($p * $questions_per_page, $total_questions);
        $page_complete = true;
        
        for ($i = $page_start; $i <= $page_end; $i++) {
            $field = "q{$i}";
            if (!isset($existing_response->$field) || $existing_response->$field === null) {
                $page_complete = false;
                break;
            }
        }
        
        if ($page_complete) {
            $max_allowed_page = $p + 1;
        } else {
            break;
        }
    }
    
    // If trying to access a page beyond allowed, redirect to max allowed
    if ($page > $max_allowed_page) {
        redirect(new moodle_url('/blocks/chaside/view.php', 
                 array('courseid' => $courseid, 'page' => $max_allowed_page)));
    }
}

// If coming from "continue test" link without explicit page, calculate which page to show
if ($existing_response && !isset($_GET['page'])) {
    // Find first unanswered question
    $first_unanswered = null;
    for ($i = 1; $i <= $total_questions; $i++) {
        $field = "q{$i}";
        if (!isset($existing_response->$field) || $existing_response->$field === null) {
            $first_unanswered = $i;
            break;
        }
    }
    
    // Calculate page for first unanswered question
    if ($first_unanswered !== null) {
        $page = ceil($first_unanswered / $questions_per_page);
    }
}

// Calculate question range for current page (needed for rendering)
$start_question = ($page - 1) * $questions_per_page + 1;
$end_question = min($page * $questions_per_page, $total_questions);

// Calculate how many questions are answered
$answered_count = 0;
if ($existing_response) {
    for ($i = 1; $i <= $total_questions; $i++) {
        $field = "q{$i}";
        if (isset($existing_response->$field) && $existing_response->$field !== null) {
            $answered_count++;
        }
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    
    // If the test is already complete, DON'T allow modifications
    if ($existing_response && $existing_response->is_completed) {
        $results_url = new moodle_url('/blocks/chaside/view_results.php', array('courseid' => $courseid));
        redirect($results_url);
    }
    
    $action = optional_param('action', 'save', PARAM_ALPHA);
    
    // Prepare base data
    $data = array(
        'userid' => $USER->id,
        'timemodified' => time()
    );
    
    // If a previous response exists, retain all existing data.
    if ($existing_response) {
        $data['id'] = $existing_response->id;
        // Copy all existing answers
        for ($i = 1; $i <= BLOCK_CHASIDE_TOTAL_QUESTIONS; $i++) {
            if (isset($existing_response->{"q{$i}"})) {
                $data["q{$i}"] = $existing_response->{"q{$i}"};
            }
        }
        // Maintain existing scores if any
        if (isset($existing_response->score_c)) $data['score_c'] = $existing_response->score_c;
        if (isset($existing_response->score_h)) $data['score_h'] = $existing_response->score_h;
        if (isset($existing_response->score_a)) $data['score_a'] = $existing_response->score_a;
        if (isset($existing_response->score_s)) $data['score_s'] = $existing_response->score_s;
        if (isset($existing_response->score_i)) $data['score_i'] = $existing_response->score_i;
        if (isset($existing_response->score_d)) $data['score_d'] = $existing_response->score_d;
        if (isset($existing_response->score_e)) $data['score_e'] = $existing_response->score_e;
        if (isset($existing_response->is_completed)) $data['is_completed'] = $existing_response->is_completed;
        if (isset($existing_response->timemodified)) $data['timemodified'] = $existing_response->timemodified;
        if (isset($existing_response->timecreated)) $data['timecreated'] = $existing_response->timecreated;
    } else {
        $data['timecreated'] = time();
        $data['is_completed'] = 0;
    }
    
    // Collect ALL responses submitted through the form (not just those on the current page)
    // This allows you to save partial progress
    $has_any_answer = false;
    for ($i = 1; $i <= $total_questions; $i++) {
        $response = optional_param("q{$i}", null, PARAM_INT);
        if ($response !== null) {
            $data["q{$i}"] = $response;
            $has_any_answer = true;
        }
    }
    
    // If there is no response AND there is no previous record, do not create an empty one.
    if (!$has_any_answer && !$existing_response) {
        // For autosave with no response, simply return success without doing anything.
        if ($action === 'autosave') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'No data to save']);
            exit;
        }
        // For browsing, allow but do not create a log.
    }
    
    // Verify that all questions on the current page are answered (for navigation purposes only)
    $current_page_complete = true;
    $missing_questions_current_page = array();
    
    for ($i = $start_question; $i <= $end_question; $i++) {
        $response = optional_param("q{$i}", null, PARAM_INT);
        if ($response !== null) {
            $data["q{$i}"] = $response;
        } else {
            // Check if there is already a previous answer to this question.
            if (!$existing_response || !isset($existing_response->{"q{$i}"}) || $existing_response->{"q{$i}"} === null) {
                $current_page_complete = false;
                $missing_questions_current_page[] = $i;
            }
        }
    }
    
    // Verify if the ENTIRE test is complete (all questions)
    $completed = true;
    for ($i = 1; $i <= $total_questions; $i++) {
        if (!isset($data["q{$i}"]) || $data["q{$i}"] === null) {
            $completed = false;
            break;
        }
    }
    
    // Process according to the button action
    switch ($action) {
        case 'autosave':
            // Silent auto-save - no validation, no redirect
            // Only save if there is at least one response
            if ($has_any_answer || $existing_response) {
                if ($existing_response) {
                    $DB->update_record('block_chaside_responses', $data);
                } else {
                    try {
                        $DB->insert_record('block_chaside_responses', $data);
                    } catch (dml_exception $e) {
                        // Race condition: another request inserted the record
                        $current_record = $DB->get_record('block_chaside_responses', array('userid' => $USER->id));
                        if ($current_record) {
                            $data['id'] = $current_record->id;
                            $DB->update_record('block_chaside_responses', $data);
                        } else {
                            throw $e;
                        }
                    }
                }
            }
            // Return JSON response for AJAX
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
            
        case 'previous':
            // Go to previous page - always allows (automatically saves only if there are replies)
            if ($has_any_answer || $existing_response) {
                if ($existing_response) {
                    $DB->update_record('block_chaside_responses', $data);
                } else {
                    try {
                        $DB->insert_record('block_chaside_responses', $data);
                    } catch (dml_exception $e) {
                        // Race condition: another request inserted the record
                        $current_record = $DB->get_record('block_chaside_responses', array('userid' => $USER->id));
                        if ($current_record) {
                            $data['id'] = $current_record->id;
                            $DB->update_record('block_chaside_responses', $data);
                        } else {
                            throw $e;
                        }
                    }
                }
            }
            
            if ($page > 1) {
                $redirect_url = new moodle_url('/blocks/chaside/view.php', array('courseid' => $courseid, 'page' => $page - 1));
                redirect($redirect_url, get_string('progress_saved', 'block_chaside'), null, \core\output\notification::NOTIFY_SUCCESS);
            }
            break;
            
        case 'next':
            // Go to the next page - only if the current page is fully answered
            if ($page < $total_pages) {
                if (!$current_page_complete) {
                    $message = get_string('complete_current_page', 'block_chaside') . ' (' . count($missing_questions_current_page) . ' ' . get_string('questions_unanswered', 'block_chaside') . ')';
                    redirect($PAGE->url, $message, null, \core\output\notification::NOTIFY_ERROR);
                } else {
                    // Save progress before navigating (only if there are answers)
                    if ($has_any_answer || $existing_response) {
                        if ($existing_response) {
                            $DB->update_record('block_chaside_responses', $data);
                        } else {
                            try {
                                $DB->insert_record('block_chaside_responses', $data);
                            } catch (dml_exception $e) {
                                // Race condition: another request inserted the record
                                $current_record = $DB->get_record('block_chaside_responses', array('userid' => $USER->id));
                                if ($current_record) {
                                    $data['id'] = $current_record->id;
                                    $DB->update_record('block_chaside_responses', $data);
                                } else {
                                    throw $e;
                                }
                            }
                        }
                    }
                    
                    $redirect_url = new moodle_url('/blocks/chaside/view.php', array('courseid' => $courseid, 'page' => $page + 1));
                    redirect($redirect_url, get_string('progress_saved', 'block_chaside'), null, \core\output\notification::NOTIFY_SUCCESS);
                }
            }
            break;
            
        case 'finish':
            // SECURITY: Validate ALL questions are answered before finishing
            $all_questions_answered = true;
            $missing_questions = array();
            
            for ($i = 1; $i <= $total_questions; $i++) {
                if (!isset($data["q{$i}"]) || $data["q{$i}"] === null) {
                    $all_questions_answered = false;
                    $missing_questions[] = $i;
                }
            }
            
            if ($all_questions_answered) {
                // Calculate scores only when fully completed
                $scores = $facade->calculate_scores($data);
                $data['score_c'] = $scores['C'];
                $data['score_h'] = $scores['H'];
                $data['score_a'] = $scores['A'];
                $data['score_s'] = $scores['S'];
                $data['score_i'] = $scores['I'];
                $data['score_d'] = $scores['D'];
                $data['score_e'] = $scores['E'];
                $data['is_completed'] = 1;
                $data['timemodified'] = time();
                
                // Update with final scores
                $DB->update_record('block_chaside_responses', $data);
                
                $course_url = new moodle_url('/course/view.php', array('id' => $courseid));
                redirect($course_url, get_string('test_completed_success', 'block_chaside'), null, \core\output\notification::NOTIFY_SUCCESS);
            } else {
                // Find first unanswered question and redirect to that page
                $first_unanswered = $missing_questions[0];
                $redirect_page = ceil($first_unanswered / $questions_per_page);
                
                $message = get_string('all_questions_must_be_answered', 'block_chaside') . ' (' . count($missing_questions) . ' ' . get_string('questions_remaining', 'block_chaside') . ')';
                $redirect_url = new moodle_url('/blocks/chaside/view.php',
                               array('courseid' => $courseid, 'page' => $redirect_page));
                redirect($redirect_url, $message, null, \core\output\notification::NOTIFY_ERROR);
            }
            break;
    }
}

// If the test is already completed, redirect to results (DO NOT allow retake)
if ($existing_response && $existing_response->is_completed) {
    $results_url = new moodle_url('/blocks/chaside/view_results.php', array('courseid' => $courseid));
    redirect($results_url);
}

echo $OUTPUT->header();

// Display chaside icon centered above title
$iconurl = new moodle_url('/blocks/chaside/pix/chaside_icon.svg');
echo '<div style="text-align: center; margin-bottom: 15px;">';
echo '<img src="' . $iconurl . '" alt="CHASIDE Icon" style="width: 70px; height: 70px; display: block; margin: 0 auto 10px auto;" />';
echo '</div>';

echo html_writer::tag('h2', get_string('test_title', 'block_chaside'), array('style' => 'text-align: center; color: #ffb600;'));
echo html_writer::tag('p', get_string('test_description', 'block_chaside'));

// Info box about mandatory questions (similar to learning_style)
echo '<div style="background-color: #fffbf0; border-left: 4px solid #ffb600; padding: 12px 16px; margin-bottom: 20px; border-radius: 4px;">';
echo '<strong>' . get_string('note', 'block_chaside') . ':</strong> ';
echo get_string('all_questions_required', 'block_chaside');
echo '</div>';


// Form
echo html_writer::start_tag('form', array('method' => 'post', 'action' => '', 'id' => 'chasideTestForm'));

// Add CSRF security token
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

for ($i = $start_question; $i <= $end_question; $i++) {
    $question_text = get_string("q{$i}", 'block_chaside');
    $current_value = '';
    
    if ($existing_response && isset($existing_response->{"q{$i}"})) {
        $current_value = $existing_response->{"q{$i}"};
    }
    
    // Main question container with border and spacing
    echo html_writer::start_tag('div', array('class' => 'card mb-3 shadow-sm', 'id' => "question-{$i}", 'data-question' => $i));
    echo html_writer::start_tag('div', array('class' => 'card-body'));
    
    // Question text
    echo html_writer::start_tag('div', array('class' => 'row align-items-center'));
    echo html_writer::start_tag('div', array('class' => 'col-md-8'));
    echo html_writer::tag('h6', 
        $question_text, 
        array('class' => 'mb-3 question-text')
    );
    echo html_writer::end_tag('div');
    
    // Opciones de respuesta organizadas
    echo html_writer::start_tag('div', array('class' => 'col-md-4'));
    echo html_writer::start_tag('div', array('class' => 'btn-group w-100', 'role' => 'group', 'aria-label' => 'Respuesta'));
    
    // Option YES
    $yes_classes = 'btn btn-outline-primary flex-fill radio-btn chaside-btn-yes';
    if ($current_value === '1') {
        $yes_classes = 'btn btn-primary flex-fill radio-btn chaside-btn-yes active';
    }
    echo html_writer::start_tag('label', array('class' => $yes_classes, 'for' => "q{$i}_yes"));
    echo html_writer::empty_tag('input', array(
        'type' => 'radio',
        'name' => "q{$i}",
        'value' => '1',
        'id' => "q{$i}_yes",
        'style' => 'position: absolute; opacity: 0;',
        'checked' => ($current_value === '1') ? 'checked' : null
    ));
    echo html_writer::tag('i', '', array('class' => 'fa fa-check me-1'));
    echo get_string('yes', 'block_chaside');
    echo html_writer::end_tag('label');
    
    // Option NO
    $no_classes = 'btn btn-outline-secondary flex-fill radio-btn chaside-btn-no';
    if ($current_value === '0') {
        $no_classes = 'btn btn-secondary flex-fill radio-btn chaside-btn-no active';
    }
    echo html_writer::start_tag('label', array('class' => $no_classes, 'for' => "q{$i}_no"));
    echo html_writer::empty_tag('input', array(
        'type' => 'radio',
        'name' => "q{$i}",
        'value' => '0',
        'id' => "q{$i}_no",
        'style' => 'position: absolute; opacity: 0;',
        'checked' => ($current_value === '0') ? 'checked' : null
    ));
    echo html_writer::tag('i', '', array('class' => 'fa fa-times me-1'));
    echo get_string('no', 'block_chaside');
    echo html_writer::end_tag('label');
    
    echo html_writer::end_tag('div'); // btn-group
    echo html_writer::end_tag('div'); // col-md-4
    echo html_writer::end_tag('div'); // row
    echo html_writer::end_tag('div'); // card-body
    echo html_writer::end_tag('div'); // card
}

// Navigation (match learning_style/personality_test layout)
echo html_writer::start_tag('div', array(
    'class' => 'navigation-buttons',
    'style' => 'display: flex; justify-content: space-between; align-items: center; margin-top: 2rem;'
));

// Left column: Previous button
echo html_writer::start_tag('div');
if ($page > 1) {
    echo html_writer::tag('button',
        get_string('btn_previous', 'block_chaside'),
        array(
            'type' => 'submit',
            'name' => 'action',
            'value' => 'previous',
            'class' => 'btn btn-secondary'
        )
    );
}
echo html_writer::end_tag('div');

// Right column: Next/Finish
echo html_writer::start_tag('div');
if ($page < $total_pages) {
    echo html_writer::tag('button',
        get_string('btn_next', 'block_chaside'),
        array(
            'type' => 'submit',
            'name' => 'action',
            'value' => 'next',
            'class' => 'btn btn-primary',
            // Plugin palette (CHASIDE yellow)
            'style' => 'background: linear-gradient(135deg, #ffd966 0%, #ffb600 100%); border: none;'
        )
    );
} else {
    echo html_writer::tag('button',
        get_string('btn_finish', 'block_chaside'),
        array(
            'type' => 'submit',
            'name' => 'action',
            'value' => 'finish',
            'id' => 'submitBtn',
            'class' => 'btn btn-success'
        )
    );
}
echo html_writer::end_tag('div');

echo html_writer::end_tag('div');
echo html_writer::end_tag('form');

// JavaScript for real-time validation and button handling
echo html_writer::start_tag('script');
echo "
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('chasideTestForm');
    if (!form) {
        return;
    }
    const radioInputs = form.querySelectorAll('input[type=\"radio\"]');
    let formAttempted = false;
    let autoSaveTimer = null;
    
    // Auto-save functionality (silent)
    function autoSaveProgress() {
        // Create FormData from current form
        const formData = new FormData(form);
        formData.set('action', 'autosave');
        
        // Use fetch to save in background
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        }).catch(error => {
            // Silent fail - don't interrupt user experience
            console.log('Auto-save error:', error);
        });
    }
    
    // Function to update the visual state of the buttons
    function updateButtonStates(questionName) {
        const yesLabel = form.querySelector('label[for=\"' + questionName + '_yes\"]');
        const noLabel = form.querySelector('label[for=\"' + questionName + '_no\"]');
        const yesInput = form.querySelector('#' + questionName + '_yes');
        const noInput = form.querySelector('#' + questionName + '_no');
        
        if (yesInput && yesInput.checked) {
            yesLabel.className = 'btn btn-primary flex-fill radio-btn chaside-btn-yes active';
            noLabel.className = 'btn btn-outline-secondary flex-fill radio-btn chaside-btn-no';
        } else if (noInput && noInput.checked) {
            noLabel.className = 'btn btn-secondary flex-fill radio-btn chaside-btn-no active';
            yesLabel.className = 'btn btn-outline-primary flex-fill radio-btn chaside-btn-yes';
        } else {
            yesLabel.className = 'btn btn-outline-primary flex-fill radio-btn chaside-btn-yes';
            noLabel.className = 'btn btn-outline-secondary flex-fill radio-btn chaside-btn-no';
        }
        
        // Remove unanswered class if answered
        if (formAttempted && (yesInput.checked || noInput.checked)) {
            const card = yesInput.closest('.card');
            if (card) {
                card.classList.remove('unanswered');
            }
        }
        
        // Trigger auto-save after answer change
        if (autoSaveTimer) {
            clearTimeout(autoSaveTimer);
        }
        autoSaveTimer = setTimeout(autoSaveProgress, 2000); // Save 2 seconds after last change
    }
    
    // Robust click handling (capture) to prevent the theme from blocking interaction.
    // Similar to the personality_test approach: delegation + value update.
    document.addEventListener('click', function(e) {
        const label = e.target.closest('label.radio-btn');
        if (!label || !form.contains(label)) {
            return;
        }

        // Get the associated input (by content or by for attribute).
        let input = label.querySelector('input[type=\"radio\"]');
        if (!input) {
            const forId = label.getAttribute('for');
            if (forId) {
                input = document.getElementById(forId);
            }
        }

        if (!input) {
            return;
        }

        // Mark and refresh UI.
        input.checked = true;
        updateButtonStates(input.name);
        validateCurrentPage();

        // Auto-save after 2s.
        if (autoSaveTimer) {
            clearTimeout(autoSaveTimer);
        }
        autoSaveTimer = setTimeout(autoSaveProgress, 2000);
    }, true);
    
    const saveBtn = form.querySelector('button[value=\"save\"]');
    const previousBtn = form.querySelector('button[value=\"previous\"]');
    const nextBtn = form.querySelector('button[value=\"next\"]');
    const finishBtn = form.querySelector('button[value=\"finish\"]');
    
    function validateCurrentPage() {
        const questions = {};
        
        // Get all questions on the current page
        radioInputs.forEach(function(input) {
            const questionName = input.name;
            if (!questions[questionName]) {
                questions[questionName] = false;
            }
            if (input.checked) {
                questions[questionName] = true;
            }
        });
        
        // Check if all questions are answered
        const allAnswered = Object.values(questions).every(function(answered) {
            return answered === true;
        });
        
        return allAnswered;
    }
    
    // Add event listeners to all radio buttons directly
    radioInputs.forEach(function(input) {
        input.addEventListener('change', function(e) {
            updateButtonStates(this.name);
            validateCurrentPage();
        });
    });

    // Initialize visual states on load (in case the theme modifies classes).
    const seenQuestions = new Set();
    radioInputs.forEach(function(input) {
        if (!seenQuestions.has(input.name)) {
            seenQuestions.add(input.name);
            updateButtonStates(input.name);
        }
    });
    
    // Prevent form submission for buttons that require validation
    form.addEventListener('submit', function(e) {
        // Get the button that was pressed
        const submitter = e.submitter;
        const action = submitter ? submitter.value : 'save';
        
        // Validate for 'next' and 'finish' buttons
        if (action !== 'next' && action !== 'finish') {
            // Permitir envío sin validación para save, previous
            return true;
        }
        
        formAttempted = true;
        
        if (!validateCurrentPage()) {
            e.preventDefault();
            
            // Visually mark unanswered questions
            const questions = {};
            radioInputs.forEach(function(input) {
                const questionName = input.name;
                if (!questions[questionName]) {
                    questions[questionName] = false;
                }
                if (input.checked) {
                    questions[questionName] = true;
                }
            });
            
            // First add 'unanswered' class to ALL unanswered cards
            Object.keys(questions).forEach(function(questionName) {
                if (!questions[questionName]) {
                    const input = form.querySelector('input[name=\"' + questionName + '\"]');
                    if (input) {
                        const card = input.closest('.card');
                        if (card) {
                            card.classList.add('unanswered');
                        }
                    }
                }
            });
            
            // Then scroll to the first unanswered question
            const firstUnanswered = document.querySelector('.card.unanswered');
            if (firstUnanswered) {
                firstUnanswered.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            
            return false;
        }
    });
});
";
echo html_writer::end_tag('script');

// Auto-scroll to first unanswered question when continuing test
if ($existing_response && $answered_count > 0 && $answered_count < 98 && !$scroll_to_finish) {
    echo html_writer::start_tag('script');
    echo "
window.addEventListener('load', function() {
    // Wait a bit for the page to fully render
    setTimeout(function() {
        // Find first unanswered question on current page
        const questionCards = Array.from(document.querySelectorAll('.card')).filter(function(card) {
            return card && card.id && card.id.indexOf('question-') === 0;
        });
        
        const firstUnansweredCard = questionCards.find(function(card) {
            return !card.querySelector('input[type=radio]:checked');
        });
        
        if (firstUnansweredCard) {
            // Store original styles
            const originalStyles = {
                border: firstUnansweredCard.style.border,
                backgroundColor: firstUnansweredCard.style.backgroundColor,
                boxShadow: firstUnansweredCard.style.boxShadow
            };
            
            firstUnansweredCard.style.setProperty('border', '2px solid #28a745', 'important');
            firstUnansweredCard.style.setProperty('background-color', '#d4edda', 'important');
            firstUnansweredCard.style.setProperty('box-shadow', '0 4px 8px rgba(40, 167, 69, 0.3)', 'important');
            firstUnansweredCard.style.transition = 'all 0.3s ease';
            
            // Scroll to it
            firstUnansweredCard.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            
            // Remove highlight after 5 seconds
            setTimeout(function() {
                firstUnansweredCard.style.border = originalStyles.border;
                firstUnansweredCard.style.backgroundColor = originalStyles.backgroundColor;
                firstUnansweredCard.style.boxShadow = originalStyles.boxShadow;
            }, 5000);
        }
    }, 300);
});
    ";
    echo html_writer::end_tag('script');
}

// Scroll to finish button when coming from block with all questions answered
if ($scroll_to_finish) {
    echo html_writer::start_tag('script');
    echo "
window.addEventListener('load', function() {
    setTimeout(function() {
        const finishBtn = document.querySelector('button[value=\"finish\"]');
        if (finishBtn) {
            finishBtn.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            
            // Add green pulsing highlight to the button
            finishBtn.style.boxShadow = '0 0 20px rgba(40, 167, 69, 0.8)';
            finishBtn.style.transition = 'all 0.3s ease';
            
            // Remove highlight after 5 seconds
            setTimeout(function() {
                finishBtn.style.boxShadow = '';
            }, 5000);
        }
    }, 300);
});
    ";
    echo html_writer::end_tag('script');
}

echo html_writer::start_tag('script');
echo "
// Track unsaved changes
window.formChanged = false;
window.originalValues = {};

// Store original values when page loads
const allRadios = document.querySelectorAll('input[type=\"radio\"]');
allRadios.forEach(function(radio) {
    if (radio.checked) {
        window.originalValues[radio.name] = radio.value;
    }
});

// Use event delegation on document to catch all radio changes
document.addEventListener('change', function(e) {
    if (e.target.type === 'radio' && e.target.name.startsWith('q')) {
        const origValue = window.originalValues[e.target.name];
        if (origValue === undefined || origValue !== e.target.value) {
            window.formChanged = true;
        } else {
            // Check if ALL values match original (in case they changed back)
            let hasChanges = false;
            document.querySelectorAll('input[type=\"radio\"]:checked').forEach(function(r) {
                if (window.originalValues[r.name] !== r.value) {
                    hasChanges = true;
                }
            });
            window.formChanged = hasChanges;
        }
    }
});
";
echo html_writer::end_tag('script');

echo $OUTPUT->footer();
