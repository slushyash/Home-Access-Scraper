<?php
require_once("RollingCurl.php");
/* 
	* 0th td = course name (PRE-IB SCI II)
	* 1st td = period/block (01)
	* 2nd td = first six week grade
	* 3rd td = second six weeks grade
	* 4th td = third six weeks grade
	* 5th td = exam 1 grade
	* 6th td = semester average (first semester)
	* 7th td = fourth six weeks grade
	* 8th td = fifth six weeks grade
	* 9th td = sixth six weeks grade
	* 10th td = Second exam grade
	* 11th td = semester 2 average
	* whew
	*/
	
	/* look @ HAC. classID starts at 0 for the first on the list, and goes on. kthxbai */
require_once("functions.php");
require_once("course.php");
class HAC {
	public $dom; // the entire dom, just in case
	public $averages; // list of trs
	public $hacID; // not studentID, the hacID, which is encrypted in some way
	public $courses; // array of courses
	

	function __construct($id) {
		// Load the DOM
		$url = "https://gradebook.roundrockisd.org/pc/displaygrades.aspx?studentid=" . $id;
		$dom = new DOMDocument();
		@$dom->loadHTML(file_get_contents($url)); 
		// Assign class vars
		$this->dom = $dom;
		$this->hacID = $id;
		// Averages: Get the second table (it's a table inside a table) and get all table rows
		$this->averages = $dom->getElementsByTagName('table')->item(1)->getElementsByTagName('tr');
		
		// Get all of the URLs. There are 6 per course. If it doesn't exist, false is filled instead.
		$urlarray = array();
		for($pos = 1; $pos < $this->averages->length; $pos++) {
			for($j = 1; $j <= 6; $j++) { // 1-6 are the cycles
				$urlarray[] = $this->getURL($pos, $j);
			}
		} 
		// Get the HTML asynchronously, then chop them into six each.
		$allHTML = array_chunk($this->getHTMLAsync($urlarray), 6);
		
		// $position = table row position
		for($position = 1; $position < $this->averages->length; $position++) {
			// get the data for a particular class 
			$higherLevel = null; // TODO
			$countsOnGPA = null; // TODO
			$teacherName = $this->getTeacherName($position);
			$data = $allHTML[$position - 1]; // 0th array element will be first "six weeks", or position
			
			$info = $this->getCourseInfo($position); // from the $data variable
			//print_r($info); // DEBUG
			$courseName = $this->getCourseNameFromPosition($position);
			// create new course, an add it to the array (after converting it to one)
			$course = new Course($higherLevel, $countsOnGPA, $teacherName, $data, $info, $courseName);
			$this->courses[] = $course->toArray();
		}
	}
	
	private function getTeacherName($position) {
		return $this->averages-> // list of rows
		item($position)-> // get the particular course
		getElementsByTagName('th')-> // teacher name is in first th
		item(0)-> // FIRST th
		textContent;
	}
	
	private function getHTMLAsync($urls) {
		$rc = new RollingCurl();
		$rc->window_size = 48; // Max should be ~ 8*6 = 48, plus some protection
		// Add URLs to request
		foreach ($urls as $url) {
			$request = new RollingCurlRequest($url);
			$rc->add($request);
		}
		$rc->execute();
		// RollingCurl saves downloaded information into an array. Let's fetch it.
		$htmlArray = $rc->array;
	
		$finalArray = array();
		// The final array isn't sorted, but we need it to be. Let's reorganize
		for($i = 0; $i < sizeof($urls); $i++) {
			for($j = 0; $j < sizeof($htmlArray); $j++) {
				if($htmlArray[$j]['url'] == $urls[$i]) { $finalArray[$i] = $htmlArray[$j]['output']; }
			}
		}
	
		return $finalArray;
	}
	
	
	private function getGrade($position, $sixWeeks) {
		$grades = $this->getCourseAverages($position);
	
		// to understand the uses of seemingly random +1s and +3s, see the above
		// if you want first six weeks grade, it's actually the SECOND td
		if($sixWeeks <= 3) { $grade = $grades->item($sixWeeks + 1)->textContent; }
	
		// look. if you want the fourth six weeks' grade, it's actually the 7th td (+3)
		else { $grade =  $grades->item($sixWeeks + 3)->textContent; }
		
		return removeSpecialChars($grade);

	} // end function
	
	private function getCourseAverages($position) {
		return $this->averages->item($position)->getElementsByTagName('td'); /* 0th tr is header columns, */
	}
	
	private function getDataString($position, $sixWeeks) {
		if($this->getGrade($position, $sixWeeks) == false) { return false; } // see if it exists
		$grades = $this->getCourseAverages($position);
		if($sixWeeks <= 3) { $domNode = $grades->item($sixWeeks + 1); }
	
		else { $domNode =  $grades->item($sixWeeks + 3); }
		$url = $domNode->childNodes->item(0)->attributes->getNamedItem('href')->nodeValue;
		$data = substr($url, 6, -25);
		$data = urldecode($data);

		return removeSpecialChars($data);
		// should return something like
		// Nnw0NDAwMTl8MTE2NTM2fDQzNTNCfDR8MjQ2OTA5fDM=
		// which when decoded = 
		// 6|440019|116536|4353B|4|246909|3
	}
	
	private function getURL($position, $sixWeeks) {
		$data = $this->getDataString($position, $sixWeeks);
		if($data == false) { return false; }
		return "https://gradebook.roundrockisd.org/pc/displaygrades.aspx?data=" . $data . "&StudentId=" . $this->hacID;
	}
	
	
	function getSemesterAverage($semesterNo) {
		$itemNo = ($semesterNo == 1) ? 6 : 11;
		return $this->averages->item($i)->getElementsByTagName('td')->item($itemno)->textContent;
	}
	
	function getCourseNameFromPosition($position) {
		$name = $this->averages->item($position)->getElementsByTagName('td');
		return $name->item(0)->textContent;
	}
	
	function getCoursePositionFromName($name) {
		for($i = 1; $i < $this->averages->length; $i++) {
			if($this->getCourseNameFromPosition($i) == $name) { return $i; }
		}
		return false;
	}
	
	function getCourseInfo($position) {
		// 6|440019|116536|4353B|4|246909|3
		$data = "";
		for($i = 1; $i <= 6; $i++) {
			if($data != false) { break; }
			$data = base64_decode($this->getDataString($position, $i));
		}
		$infoArray = array();
		$infoArray['cycle'] = substr($data, 0, 1);
		$infoArray['studentid'] = substr($data, 2, 6);
		$infoArray['teacherid'] = substr($data, 9, 6);
		$infoArray['courseID'] = substr($data, 16, 5);
		return $infoArray;
		// we can extract more later
		
	}
	
	/* this returns an array, NOT a domnodelist */
	function getElementsByClassName($ClassName) {
		return getElementsByClassName($ClassName, $this->dom);
	}
	
	/* if we're dealing with an unsemantic web page ,this will return the first one */
	/* also returns an array */
	function getElementById($idName) {
		return getElementById($idName, $this->dom);
	}


}

