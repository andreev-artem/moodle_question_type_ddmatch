<?php

/**
 * Drag&drop matching question renderer class.
 *
 * @package    qtype
 * @subpackage ddmatch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();


/**
 * Generates the output for drag&drop matching questions.
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_ddmatch_renderer extends qtype_with_combined_feedback_renderer {

    public function head_code(question_attempt $qa) {
        global $PAGE;

        if ($this->can_use_drag_and_drop())
            $PAGE->requires->js('/question/type/ddmatch/script.js');
    }

    public function formulation_and_controls(question_attempt $qa,
            question_display_options $options) {
        return $this->construct_qa_with_select($qa, $options);
    }

    protected function can_use_drag_and_drop() {
        global $USER;

        $ie = check_browser_version('MSIE', 6.0);
        $ff = check_browser_version('Gecko', 20051106);
        $op = check_browser_version('Opera', 9.0);
        $sa = check_browser_version('Safari', 412);
        $ch = check_browser_version('Chrome', 6);

        if ((!$ie && !$ff && !$op && !$sa && !$ch) or !empty($USER->screenreader)) {
            return false;
        }

        return true;
    }

    // used when js disabled
    protected function construct_qa_with_select(question_attempt $qa,
            question_display_options $options) {

        $question = $qa->get_question();
        $stemorder = $question->get_stem_order();
        $response = $qa->get_last_qt_data();

        $choices = $this->format_choices($question);

        $result = '';
        $result .= html_writer::tag('div', $question->format_questiontext($qa),
                array('class' => 'qtext'));

        $result .= html_writer::start_tag('div', array('class' => 'ablock'));
        $result .= html_writer::start_tag('table', array('class' => 'answer'));
        $result .= html_writer::start_tag('tbody');

        $parity = 0;
        foreach ($stemorder as $key => $stemid) {

            $result .= html_writer::start_tag('tr', array('class' => 'r' . $parity));
            $fieldname = 'sub' . $key;

            $result .= html_writer::tag('td', $question->format_text(
                    $question->stems[$stemid], $question->stemformat[$stemid],
                    $qa, 'qtype_ddmatch', 'subquestion', $stemid),
                    array('class' => 'text'));

            $classes = 'control';
            $feedbackimage = '';

            if (array_key_exists($fieldname, $response)) {
                $selected = $response[$fieldname];
            } else {
                $selected = 0;
            }

            $fraction = (int) ($selected && $selected == $question->get_right_choice_for($stemid));

            if ($options->correctness && $selected) {
                $classes .= ' ' . $this->feedback_class($fraction);
                $feedbackimage = $this->feedback_image($fraction);
            }

            $result .= html_writer::tag('td',
                    html_writer::select($choices, $qa->get_qt_field_name('sub' . $key), $selected,
                            array('0' => 'choose'), array('disabled' => $options->readonly)) .
                    ' ' . $feedbackimage, array('class' => $classes));

            $result .= html_writer::end_tag('tr');
            $parity = 1 - $parity;
        }
        $result .= html_writer::end_tag('tbody');
        $result .= html_writer::end_tag('table');

        $result .= html_writer::end_tag('div'); // ablock

        if ($qa->get_state() == question_state::$invalid) {
            $result .= html_writer::nonempty_tag('div',
                    $question->get_validation_error($response),
                    array('class' => 'validationerror'));
        }

        return $result;
    }

    public function specific_feedback(question_attempt $qa) {
        return $this->combined_feedback($qa);
    }

    protected function format_choices($question) {
        $choices = array();
        foreach ($question->get_choice_order() as $key => $choiceid) {
            $choices[$key] = htmlspecialchars($question->choices[$choiceid]);
        }
        return $choices;
    }

    public function correct_response(question_attempt $qa) {
        $question = $qa->get_question();
        $stemorder = $question->get_stem_order();

        $choices = $this->format_choices($question);
        $right = array();
        foreach ($stemorder as $key => $stemid) {
            $right[] = $question->format_text($question->stems[$stemid],
                    $question->stemformat[$stemid], $qa,
                    'qtype_ddmatch', 'subquestion', $stemid) . ' – ' .
                    $choices[$question->get_right_choice_for($stemid)];
        }

        if (!empty($right)) {
            return get_string('correctansweris', 'qtype_ddmatch', implode(', ', $right));
        }
    }
}
