<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package    moodlecore
 * @subpackage backup-moodle2
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * restore plugin class that provides the necessary information
 * needed to restore one match qtype plugin
 */
class restore_qtype_ddmatch_plugin extends restore_qtype_plugin {

    /**
     * Returns the paths to be handled by the plugin at question level
     */
    protected function define_question_plugin_structure() {

        $paths = array();

        // Add own qtype stuff
        $elename = 'matchoptions';
        $elepath = $this->get_pathfor('/matchoptions'); // we used get_recommended_name() so this works
        $paths[] = new restore_path_element($elename, $elepath);

        $elename = 'match';
        $elepath = $this->get_pathfor('/matches/match'); // we used get_recommended_name() so this works
        $paths[] = new restore_path_element($elename, $elepath);


        return $paths; // And we return the interesting paths
    }

    /**
     * Process the qtype/matchoptions element
     */
    public function process_matchoptions($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        // Detect if the question is created or mapped
        $oldquestionid   = $this->get_old_parentid('question');
        $newquestionid   = $this->get_new_parentid('question');
        $questioncreated = $this->get_mappingid('question_created', $oldquestionid) ? true : false;

        // If the question has been created by restore, we need to create its question_ddmatch too
        if ($questioncreated) {
            // Adjust some columns
            $data->question = $newquestionid;
            // Keep question_ddmatch->subquestions unmodified
            // after_execute_question() will perform the remapping once all subquestions
            // have been created
            // Insert record
            $newitemid = $DB->insert_record('question_ddmatch', $data);
            // Create mapping
            $this->set_mapping('question_ddmatch', $oldid, $newitemid);
        } else {
            // Nothing to remap if the question already existed
        }
    }

    /**
     * Process the qtype/matches/match element
     */
    public function process_match($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        // Detect if the question is created or mapped
        $oldquestionid   = $this->get_old_parentid('question');
        $newquestionid   = $this->get_new_parentid('question');
        $questioncreated = $this->get_mappingid('question_created', $oldquestionid) ? true : false;

        // If the question has been created by restore, we need to create its question_ddmatch_sub too
        if ($questioncreated) {
            // Adjust some columns
            $data->question = $newquestionid;
            // Insert record
            $newitemid = $DB->insert_record('question_ddmatch_sub', $data);
            // Create mapping (there are files and states based on this)
            $this->set_mapping('question_ddmatch_sub', $oldid, $newitemid);

        // match questions require mapping of question_ddmatch_sub, because
        // they are used by question_states->answer
        } else {
            // Look for matching subquestion (by question, questiontext and answertext)
            $sub = $DB->get_record_select('question_ddmatch_sub',
                'question = ? AND '.$DB->sql_compare_text('questiontext').' = '.$DB->sql_compare_text('?').' AND answertext = ?',
                array($newquestionid, $data->questiontext, $data->answertext), 'id', IGNORE_MULTIPLE);
            // Found, let's create the mapping
            if ($sub) {
                $this->set_mapping('question_ddmatch_sub', $oldid, $sub->id);
            // Something went really wrong, cannot map subquestion for one match question
            } else {
                throw restore_step_exception('error_question_ddmatch_sub_missing_in_db', $data);
            }
        }
    }

    /**
     * This method is executed once the whole restore_structure_step,
     * more exactly ({@link restore_create_categories_and_questions})
     * has ended processing the whole xml structure. Its name is:
     * "after_execute_" + connectionpoint ("question")
     *
     * For match qtype we use it to restore the subquestions column,
     * containing one list of question_ddmatch_sub ids
     */
    public function after_execute_question() {
        global $DB;
        // Now that all the question_ddmatch_subs have been restored, let's process
        // the created question_ddmatch subquestions (list of question_ddmatch_sub ids)
        $rs = $DB->get_recordset_sql("SELECT qm.id, qm.subquestions
                                        FROM {question_ddmatch} qm
                                        JOIN {backup_ids_temp} bi ON bi.newitemid = qm.question
                                       WHERE bi.backupid = ?
                                         AND bi.itemname = 'question_created'", array($this->get_restoreid()));
        foreach ($rs as $rec) {
            $subquestionsarr = explode(',', $rec->subquestions);
            foreach ($subquestionsarr as $key => $subquestion) {
                $subquestionsarr[$key] = $this->get_mappingid('question_ddmatch_sub', $subquestion);
            }
            $subquestions = implode(',', $subquestionsarr);
            $DB->set_field('question_ddmatch', 'subquestions', $subquestions, array('id' => $rec->id));
        }
        $rs->close();
    }

    /**
     * Given one question_states record, return the answer
     * recoded pointing to all the restored stuff for match questions
     *
     * answer is one comma separated list of hypen separated pairs
     * containing question_ddmatch_sub->id and question_ddmatch_sub->code
     */
    public function recode_state_answer($state) {
        $answer = $state->answer;
        $resultarr = array();
        foreach (explode(',', $answer) as $pair) {
            $pairarr = explode('-', $pair);
            $id = $pairarr[0];
            $code = $pairarr[1];
            $newid = $this->get_mappingid('question_ddmatch_sub', $id);
            $resultarr[] = implode('-', array($newid, $code));
        }
        return implode(',', $resultarr);
    }

    /**
     * Return the contents of this qtype to be processed by the links decoder
     */
    static public function define_decode_contents() {

        $contents = array();

        $contents[] = new restore_decode_content('question_ddmatch_sub', array('questiontext'), 'question_ddmatch_sub');

        return $contents;
    }
}
