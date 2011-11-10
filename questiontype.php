<?php

/**
 * Question type class for the drag&drop matching question type.
 *
 * @package    qtype
 * @subpackage ddmatch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');


/**
 * The drag&drop matching question type class.
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_ddmatch extends question_type {

    public function get_question_options($question) {
        global $DB;
        parent::get_question_options($question);
        $question->options = $DB->get_record('question_ddmatch', array('question' => $question->id));
        $question->options->subquestions = $DB->get_records('question_ddmatch_sub',
                array('question' => $question->id), 'id ASC');
        return true;
    }

    public function save_question_options($question) {
        global $DB;
        $context = $question->context;
        $result = new stdClass();

        $oldsubquestions = $DB->get_records('question_ddmatch_sub',
                array('question' => $question->id), 'id ASC');

        // $subquestions will be an array with subquestion ids
        $subquestions = array();

        // Insert all the new question+answer pairs
        foreach ($question->subquestions as $key => $questiontext) {
            if ($questiontext['text'] == '' && trim($question->subanswers[$key]['text']) == '') {
                continue;
            }
            if ($questiontext['text'] != '' && trim($question->subanswers[$key]['text']) == '') {
                $result->notice = get_string('nomatchinganswer', 'qtype_ddmatch', $questiontext);
            }

            // Update an existing subquestion if possible.
            $subquestion = array_shift($oldsubquestions);
            if (!$subquestion) {
                $subquestion = new stdClass();
                // Determine a unique random code
                $subquestion->code = rand(1, 999999999);
                while ($DB->record_exists('question_ddmatch_sub',
                        array('code' => $subquestion->code, 'question' => $question->id))) {
                    $subquestion->code = rand(1, 999999999);
                }
                $subquestion->question = $question->id;
                $subquestion->questiontext = '';
                $subquestion->answertext = '';
                $subquestion->id = $DB->insert_record('question_ddmatch_sub', $subquestion);
            }

            $subquestion->questiontext = $this->import_or_save_files($questiontext,
                    $context, 'qtype_ddmatch', 'subquestion', $subquestion->id);
            $subquestion->questiontextformat = $questiontext['format'];
            $subquestion->answertext = $this->import_or_save_files($question->subanswers[$key],
                    $context, 'qtype_ddmatch', 'subanswer', $subquestion->id);
            $subquestion->answertextformat = $question->subanswers[$key]['format'];

            $DB->update_record('question_ddmatch_sub', $subquestion);

            $subquestions[] = $subquestion->id;
        }

        // Delete old subquestions records
        $fs = get_file_storage();
        foreach ($oldsubquestions as $oldsub) {
            $fs->delete_area_files($context->id, 'qtype_ddmatch', 'subquestion', $oldsub->id);
            $fs->delete_area_files($context->id, 'qtype_ddmatch', 'subanswer', $oldsub->id);
            $DB->delete_records('question_ddmatch_sub', array('id' => $oldsub->id));
        }

        // Save the question options.
        $options = $DB->get_record('question_ddmatch', array('question' => $question->id));
        if (!$options) {
            $options = new stdClass();
            $options->question = $question->id;
            $options->correctfeedback = '';
            $options->partiallycorrectfeedback = '';
            $options->incorrectfeedback = '';
            $options->id = $DB->insert_record('question_ddmatch', $options);
        }

        $options->subquestions = implode(',', $subquestions);
        $options->shuffleanswers = $question->shuffleanswers;
        $options = $this->save_combined_feedback_helper($options, $question, $context, true);
        $DB->update_record('question_ddmatch', $options);

        $this->save_hints($question, true);

        if (!empty($result->notice)) {
            return $result;
        }

        if (count($subquestions) < 3) {
            $result->notice = get_string('notenoughanswers', 'question', 3);
            return $result;
        }

        return true;
    }

    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);

        $question->shufflestems = $questiondata->options->shuffleanswers;
        $this->initialise_combined_feedback($question, $questiondata, true);

        $question->stems = array();
        $question->choices = array();
        $question->right = array();

        foreach ($questiondata->options->subquestions as $matchsub) {
            $ans = $matchsub->answertext;
            $key = array_search($matchsub->answertext, $question->choices);
            if ($key === false) {
                $key = $matchsub->id;
                $question->choices[$key] = $matchsub->answertext;
                $question->choiceformat[$key] = $matchsub->answertextformat;
            }

            if ($matchsub->questiontext !== '') {
                $question->stems[$matchsub->id] = $matchsub->questiontext;
                $question->stemformat[$matchsub->id] = $matchsub->questiontextformat;
                $question->right[$matchsub->id] = $key;
            }
        }
    }

    protected function make_hint($hint) {
        return question_hint_with_parts::load_from_record($hint);
    }

    public function delete_question($questionid, $contextid) {
        global $DB;
        
        $DB->delete_records('question_ddmatch', array('question' => $questionid));
        $DB->delete_records('question_ddmatch_sub', array('question' => $questionid));

        parent::delete_question($questionid, $contextid);
    }

    public function get_random_guess_score($questiondata) {
        $q = $this->make_question($questiondata);
        
        return 1 / count($q->choices);
    }

    public function get_possible_responses($questiondata) {
        $subqs = array();

        $q = $this->make_question($questiondata);

        foreach ($q->stems as $stemid => $stem) {

            $responses = array();
            foreach ($q->choices as $choiceid => $choice) {
                $stemhtml = $q->html_to_text($stem, $q->stemformat[$stemid]);
                $choicehtml = $q->html_to_text($choice, $q->choiceformat[$choiceid]);
                
                $responses[$choiceid] = new question_possible_response(
                         $stemhtml. ': ' . $choicehtml,
                        ($choiceid == $q->right[$stemid]) / count($q->stems));
            }
            $responses[null] = question_possible_response::no_response();

            $subqs[$stemid] = $responses;
        }

        return $subqs;
    }

    public function move_files($questionid, $oldcontextid, $newcontextid) {
        global $DB;
        $fs = get_file_storage();

        parent::move_files($questionid, $oldcontextid, $newcontextid);

        $subquestionids = $DB->get_records_menu('question_ddmatch_sub',
                array('question' => $questionid), 'id', 'id,1');
        foreach ($subquestionids as $subquestionid => $notused) {
            $fs->move_area_files_to_new_context($oldcontextid,
                    $newcontextid, 'qtype_ddmatch', 'subquestion', $subquestionid);
            $fs->move_area_files_to_new_context($oldcontextid,
                    $newcontextid, 'qtype_ddmatch', 'subanswer', $subquestionid);
        }
    }

    protected function delete_files($questionid, $contextid) {
        global $DB;
        $fs = get_file_storage();

        parent::delete_files($questionid, $contextid);

        $subquestionids = $DB->get_records_menu('question_ddmatch_sub',
                array('question' => $questionid), 'id', 'id,1');
        foreach ($subquestionids as $subquestionid => $notused) {
            $fs->delete_area_files($contextid, 'qtype_ddmatch', 'subquestion', $subquestionid);
            $fs->delete_area_files($contextid, 'qtype_ddmatch', 'subanswer', $subquestionid);
        }

        $fs->delete_area_files($contextid, 'qtype_ddmatch',
                'correctfeedback', $questionid);
        $fs->delete_area_files($contextid, 'qtype_ddmatch',
                'partiallycorrectfeedback', $questionid);
        $fs->delete_area_files($contextid, 'qtype_ddmatch',
                'incorrectfeedback', $questionid);
    }
	
/// IMPORT EXPORT FUNCTIONS ////////////////////////////
    /**
     * Translate human readable format name
     * into internal Moodle code number
     * @param string name format name from xml file
     * @return int Moodle format code
	 * copied from format/xml/format.php
	 * because can't be used as declared protected
     */
    protected function trans_format($name) {
        $name = trim($name);

        if ($name == 'moodle_auto_format') {
            return FORMAT_MOODLE;
        } else if ($name == 'html') {
            return FORMAT_HTML;
        } else if ($name == 'plain_text') {
            return FORMAT_PLAIN;
        } else if ($name == 'wiki_like') {
            return FORMAT_WIKI;
        } else if ($name == 'markdown') {
            return FORMAT_MARKDOWN;
        } else {
            return 0; // or maybe warning required
        }
    }
	
    /**
     * Convert internal Moodle text format code into
     * human readable form
     * @param int id internal code
     * @return string format text
	 * copied from format/xml/format.php
	 * because can't be used as declared protected
     */
    protected function get_format($id) {
        switch($id) {
            case FORMAT_MOODLE:
                return 'moodle_auto_format';
            case FORMAT_HTML:
                return 'html';
            case FORMAT_PLAIN:
                return 'plain_text';
            case FORMAT_WIKI:
                return 'wiki_like';
            case FORMAT_MARKDOWN:
                return 'markdown';
            default:
                return 'unknown';
        }
    }
	
    /**
    * Convert files into text output in the given format.
    * This method is copied from qformat_default as a quick fix, as the method there is
    * protected.
    * @param array
    * @param string encoding method
    * @return string $string
    */
    public function write_files($files, $indent) {
        if (empty($files)) {
            return '';
        }
        $string = '';
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $string .= str_repeat(' ', $indent);
            $string .= '<file name="' . $file->get_filename() . '" encoding="base64">';
            $string .= base64_encode($file->get_content());
            $string .= "</file>\n";
        }
        return $string;
    }
    /**
     ** Provide export functionality for xml format
     ** @param question object the question object
     ** @param format object the format object so that helper methods can be used 
     ** @param extra mixed any additional format specific data that may be passed by the format (see format code for info)
     ** @return string the data to append to the output buffer or false if error
     **/
    function export_to_xml( $question, $format, $extra ) {
        $expout = '';
        $fs = get_file_storage();
        $contextid = $question->contextid;
		$expout .= $format->write_combined_feedback($question->options);
        $expout .= $format->write_hints($question);
        foreach($question->options->subquestions as $subquestion) {
            $files = $fs->get_area_files($contextid, 'qtype_ddmatch', 'subquestion', $subquestion->id);
            $textformat = $this->get_format($subquestion->questiontextformat);
            $expout .= "<subquestion format=\"$textformat\">\n";
            $expout .= $format->writetext( $subquestion->questiontext );
            $expout .= $this->write_files($files, 2);
            $expout .= "<answer format=\"$textformat\">\n";
			$expout .= $format->writetext( $subquestion->answertext );
			$files = $fs->get_area_files($contextid, 'qtype_ddmatch', 'subanswer', $subquestion->id);
			$expout .= $this->write_files($files, 2);
			$expout .= "</answer>\n";
            $expout .= "</subquestion>\n";
        }

        return $expout;
    }

   /**
    ** Provide import functionality for xml format
    ** @param data mixed the segment of data containing the question
    ** @param question object question object processed (so far) by standard import code
    ** @param format object the format object so that helper methods can be used (in particular error() )
    ** @param extra mixed any additional format specific data that may be passed by the format (see format code for info)
    ** @return object question object suitable for save_options() call or false if cannot handle
    **/
   function import_from_xml( $data, $question, $format, $extra=null ) {
       // check question is for us
       $qtype = $data['@']['type'];
       if ($qtype=='ddmatch') {
           $question = $format->import_headers( $data );

            // header parts particular to matching
            $question->qtype = $qtype;
            $question->shuffleanswers = $format->getpath( $data, array( '#','shuffleanswers',0,'#' ), 1 );
        
            // get subquestions
            $subquestions = $data['#']['subquestion'];
            $question->subquestions = array();
            $question->subanswers = array();

            // run through subquestions
            foreach ($subquestions as $subquestion) {
                $qo = array();
                $qo['text'] = $format->getpath($subquestion, array('#', 'text', 0, '#'), '', true);
                $qo['format'] = $this->trans_format(
                        $format->getpath($subquestion, array('@', 'format'), 'html'));
                $qo['files'] = array();

                $files = $format->getpath($subquestion, array('#', 'file'), array());
                foreach ($files as $file) {
                    $record = new stdclass();
                    $record->content = $file['#'];
                    $record->encoding = $file['@']['encoding'];
                    $record->name = $file['@']['name'];
                    $qo['files'][] = $record;
                }
                $question->subquestions[] = $qo;
				$answers = $format->getpath($subquestion, array('#', 'answer', 0), array());
				$ans = array();
				$ans['text'] = $format->getpath($subquestion, array('#','answer',0,'#','text',0,'#'), '', true);
				$ans['format'] = $this->trans_format(
                        $format->getpath($answers, array('@', 'format'), 'html'));
                $ans['files'] = array();
                $files = $format->getpath($answers, array('#', 'file'), array());
                foreach ($files as $file) {
                    $record = new stdclass();
                    $record->content = $file['#'];
                    $record->encoding = $file['@']['encoding'];
                    $record->name = $file['@']['name'];
                    $ans['files'][] = $record;
                }
                $question->subanswers[] = $ans;
            }
			echo "ici";var_dump($question);var_dump($data);
			$format->import_combined_feedback($question, $data, true);
			$format->import_hints($question, $data, true);
            return $question;
       }
       else {
           return false;
       }
    } 
}
