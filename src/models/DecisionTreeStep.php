<?php

class DecisionTreeStep extends DataObject
{
	private static $db = [
		'Title' => 'Varchar(255)',
		'Type' => "Enum('Question, Result')",
		'Content' => 'HTMLText',
		'HideTitle' => 'Boolean'
	];

	private static $has_many = [
		'Answers' => 'DecisionTreeAnswer.Question'
	];

	private static $belongs_to = [
		'ParentAnswer' => 'DecisionTreeAnswer.ResultingStep',
		'ParentElement' => 'ElementDecisionTree.FirstStep'
	];

	private static $summary_fields = [
		'ID' => 'ID',
		'Title' => 'Title',
		'getAnswerTreeForGrid' => 'Answers'
	];

	private static $default_result_title = 'Our recommendation';

	public function getCMSFields()
	{
		$fields = parent::getCMSFields();

		$content = $fields->dataFieldByname('Content');
		$content->setRows(4);

		$fields->removeByName('Answers');

		// Allow to hide the title only on Result
		$hideTitle = CheckboxField::create('HideTitle', 'HideTitle');
		$hideTitle->displayIf('Type')->isEqualTo('Result')->end();
		$fields->insertAfter($hideTitle, 'Title');

		if ($this->IsInDB()) {
			// Display Parent Answer
			if ($this->ParentAnswer()->exists()) {
				$parentAnswerTitle = ReadOnlyField::create('ParentAnswerTitle', 'Parent Answer', $this->ParentAnswer()->TitleWithQuestion());
				$fields->addFieldToTab('Root.Main', $parentAnswerTitle, 'Title');
			}

			// List answers
			$answerConfig = GridFieldConfig_RecordEditor::create();
			$answerConfig->addComponent(new GridFieldOrderableRows('Sort'));
			$answerGrid = GridField::create(
				'Answers',
				'Answers',
				$this->Answers(),
				$answerConfig
			);

			$fields->addFieldTotab('Root.Main', DisplayLogicWrapper::create($answerGrid)->displayUnless('Type')->isEqualTo('Result')->end());

			// Add Tree Preview
			// Note: cannot add it if the object is not in DB
			$fields->addFieldToTab('Root.Tree', DecisionTreeStepPreview::create('Tree', $this->getTreeOrigin()));
		}

		return $fields;
	}

	/**
	* Set default title on Result steps
	*/
	public function onBeforeWrite()
	{
		if ($this->Type == 'Result' && !$this->Title) {
			$this->Title = $this->config()->default_result_title;
		}

		parent::onBeforeWrite();
	}

	/**
	* Permissions
	*/
	public function canCreate($member = null) 
	{
		return singleton('ElementDecisionTree')->canCreate($member);
	}

	public function canView($member = null)
	{
		return singleton('ElementDecisionTree')->canCreate($member);
	}

	public function canEdit($member = null) 
	{
		return singleton('ElementDecisionTree')->canCreate($member);
	}

	/**
	* Prevent deleting Step with answers that have dependant questions
	*/
	public function candelete($member = null)
	{
		$canDelete = singleton('ElementDecisionTree')->canDelete($member);

		foreach($this->Answers() as $answer) {
			if (!$answer->canDelete()) {
				$canDelete = false;
			}
		}

		return $canDelete;
	}

	/**
	* When deleting a step, delete also its answers if they don't have a subsequent question
	*/
	public function onBeforeDelete()
	{
		parent::onBeforeDelete();

		foreach($this->Answers() as $answer) {
			if ($answer->canDelete()) {
				$asnwer->delete();
			}
		}
	}

	/**
	* Return a readable list of the answer title and the title of the question
	* which will be displayed if the answer is selected
	* Used for Gridfield
	*
	* @return HTMLText
	*/
	public function getAnswerTreeForGrid()
	{
		$output = '';
		if ($this->Answers()->Count()) {
			foreach($this->Answers() as $answer) {
				$output .= $answer->Title;
				if ($answer->ResultingStep()) {
					$output .= ' => '.$answer->ResultingStep()->Title;
				}
				$output .= '<br/>';
			}
		}

		$html = HTMLText::create('Answers');
		$html->setValue($output);

		return $html;
	}

	/**
	* Outputs an optionset to allow user to select an answer to the question
	*
	* @return OptionsetField
	*/
	public function getAnswersOptionset()
	{
		$source = array();
		foreach($this->Answers() as $answer) {
			$source[$answer->ID] = $answer->Title;
		}

		return OptionsetField::create('stepanswerid', '', $source)->addExtraClass('decisiontree-option');
	}

	/**
	* Return the DecisionAnswer rsponsible for displaying this step
	*
	* @return DecisionTreeAnswer
	*/
	public function getParentAnswer()
	{
		return DecisionTreeAnswer::get()->filter('ResultingStepID', $this->ID)->First();
	}

	/**
	* Return the list of DecisionTreeAnswer ID
	* leading to this step being displayed
	*
	* @return Array
	*/
	public function getAnswerPathway(&$idList = array())
	{
		if ($answer = $this->getParentAnswer()) {
			array_push($idList, $answer->ID);
			if ($question = $answer->Question()) {
				$question->getAnswerPathway($idList);
			}
		}

		return $idList;
	}

	/**
	* Return the list of DecisionTreeStep ID
	* leading to this step being displayed
	*
	* @return Array
	*/
	public function getQuestionPathway(&$idList = array())
	{
		array_push($idList, $this->ID);
		if ($answer = $this->getParentAnswer()) {
			if ($question = $answer->Question()) {
				$question->getQuestionPathway($idList);
			}
		}

		return $idList;
	}

	/**
	* Builds an array of question and answers leading to this Step
	* Each entry is an array which key is either 'question' or 'answer'
	* and value is the ID of the object
	* Note: the array is in reverse order
	*
	* @return Array
	*/
	public function getFullPathway(&$path = array())
	{
		if ($answer = $this->getParentAnswer()) {
			array_push($path, array('question' => $this->ID));
			array_push($path, array('answer' => $answer->ID));
			if ($question = $answer->Question()) {
				$question->getFullPathway($path);
			}
		} else {
			array_push($path, array('question' => $this->ID));
		}

		return $path;
	}

	/**
	* Find the very first DecisionStep in the tree
	*
	* @return DecisionTreeStep
	*/
	public function getTreeOrigin()
	{
		$pathway = array_reverse($this->getQuestionPathway());
		return DecisionTreeStep::get()->byID($pathway[0]);
	}

	/**
	* Return this step position in the pathway
	* Used to number step on the front end
	*
	* @return Int
	*/
	public function getPositionInPathway()
	{
		$pathway = array_reverse($this->getFullPathway());
		// Pathway has both questions and answers
		// so need to retain ids of questions only
		$id = array_column($pathway, 'question');

		$pos = array_search($this->ID, $id);

		return ($pos === false) ? 0 : $pos + 1;
	}

	/**
	* Return a DataList of DecisionTreeStep that do not belong to a Tree
	*
	* @return DataList
	*/
	public static function get_orphans()
	{
		$orphans = DecisionTreeStep::get()->filterByCallback(function($item) {
			return !$item->belongsToTree();
		});

		return DecisionTreeStep::get()->filter('ID', $orphans->column('ID'));
	}

	/**
	* Return a DataList of all DecisionTreeStep that do not belong to an answer
	* ie. are the first child of a element
	*
	* @return DataList
	*/
	public static function get_initial_steps()
	{
		$intial = DecisionTreeStep::get()->filterByCallback(function($item) {
			return !$item->belongsToAnswer();
		});

		return DecisionTreeStep::get()->filter('ID', $intial->column('ID'))->exclude('Type', 'Result');
	}

	/**
	*
	*/
	public function belongsToTree()
	{
		return ($this->belongsToElement() || $this->belongsToAnswer());
	}

	/**
	*
	*/
	public function belongsToElement()
	{
		return (ElementDecisionTree::get()->filter('FirstStepID', $this->ID)->Count() > 0);
	}

	/**
	*
	*/
	public function belongsToAnswer()
	{
		return ($this->ParentAnswer() && $this->ParentAnswer()->exists());
	}

	/**
	* Checks if this object is currently being edited in the CMS
	* by comparing its ID with the one in the request
	*
	* @return Boolean
	*/
	public function IsCurrentlyEdited()
	{
		$request = Controller::curr()->getRequest();
		$class = $request->param('FieldName');
		$currentID = $request->param('ID');

		$stepRelationships = ['ResultingStep', 'FirstStep'];

		if ($currentID && in_array($class, $stepRelationships)) {
			return  $currentID == $this->ID;
		}

		return false;
	}

	/**
	* Create a link that allowd to edit this object in the CMS
	* To do this, it rewinds the tree up to the element
	* then append its edit url to the edit url of its parent question
	*
	* @return String
	*/
	public function CMSEditLink() {
		$origin = $this->getTreeOrigin();
		if ($origin) {
			$root = $origin->ParentElement();
			if ($root) {
				$url = Controller::join_links($root->CMSEditFirstStepLink(), $this->getRecursiveEditPath());
				return $url;
			}
		}
	}

	/**
	* Build url to allow to edit this object
	*
	* @return String
	*/
	public function getRecursiveEditPath()
	{
		$pathway = array_reverse($this->getFullPathway());
		unset($pathway[0]); // remove first question

		$url = '';
		foreach($pathway as $step) {
			if (is_array($step) && !empty($step)) {
				$type = array_keys($step)[0];
				$id = $step[$type];

				if ($type == 'question') {
					$url .= '/ItemEditForm/field/ResultingStep/item/'.$id;
				} else if ($type == 'answer') {
					$url .= '/ItemEditForm/field/Answers/item/'.$id;
				}
			}
		}

		return $url;
	}
}