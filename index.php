<?php
// 0. show errors
if(false) {
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);
}

// 1. set the default time zone
date_default_timezone_set('UTC');

// 2. get the current slug, this is the identifier of what comments will be shown / posted towards.
if(
	isset($_GET['slug']) && 
	!empty($_GET['slug']) && 
	gettype($_GET['slug']) == 'string'
) {
	$postSlug = $_GET['slug'];
} else {
	if(!isset($_SERVER['PATH_INFO'])) {
		$pathInfo = '/';
	} else {
		$pathInfo = $_SERVER['PATH_INFO'];
	}
	$postSlug = $pathInfo;
}

// 3. start the database connection
$dbh = new PDO('sqlite:main.db');

// 4. setup vars to restore previous formdata, in case of errors
$commentData = ['name' => '', 'comment' => ''];
$emptyCommentData = $commentData;

// 5. handle the posting of a new comment
if($_SERVER['REQUEST_METHOD'] === 'POST') {
	
	// 5.1. check if name is supplied
	if(isset($_POST['name']) && !empty($_POST['name'])) {
		$name = $_POST['name'];
	} else {
		getComments(true, 'Name has to be supplied', $commentData);
		return;
	}
	
	// 5.2. check if comment is supplied
	if(isset($_POST['comment']) && !empty($_POST['comment'])) {
		$comment = $_POST['comment'];
	} else {
		getComments(true, 'Comment has to be supplied', $commentData);
		return;
	}
	
	// 5.3. type check name and comment
	if(
		gettype($name) != 'string' ||
		gettype($comment) != 'string'
	) {
		getComments(true, 'Name or Comment is not a string', $commentData);
		return;
	}
	
	// 5.4. set the commentdata, so that it will be retrieved
	$commentData['name'] = $name;
	$commentData['comment'] = $comment;
	
	// 5.5. test the name for a minimum and maximum length
	$nameLength = strlen($name);
	if(strlen($name) < 4) {
		getComments(true, 'Name cannot be shorter than 4 characters, it is currently ' . $nameLength . ' characters long', $commentData);
		return;
	}
	if(strlen($name) > 50) {
		getComments(true, 'Name cannot be longer than 50 characters, it is currently ' . $nameLength . ' characters long', $commentData);
		return;
	}
	
	// 5.6. test the comment for a minimum and maximum length
	$commentLength = strlen($comment);
	if($commentLength < 10) {
		getComments(true, 'Comment cannot be shorter than 10 characters, it is currently ' . $commentLength . ' characters long', $commentData);
		return;
	}
	if($commentLength > 250) {
		getComments(true, 'Comment cannot be longer than 250 characters, it is currently ' . $commentLength . ' characters long', $commentData);
		return;
	}
	
	
	// 5.7. post the comment into the database
	$stmt = $dbh->prepare('INSERT INTO comments (postslug, commentor, comment, datetime_) VALUES (:postslug, :name, :comment, :datetime_)');
	
	// 5.8. handle database error
	if($stmt === FALSE) {
		writeError($dbh->errorInfo());
		getComments(true, 'Something went wrong with the database', $commentData);
		return;
	}
	
	// 5.9. get a string of the current time
	$nowDate = new DateTime();
	$dateTimeStr = $nowDate->format('Y-m-d H:i:s');
	
	
	
	// 5.10. post the comment
	$res = $stmt->execute([':postslug' => $postSlug, ':name' => $name, ':comment' => $comment, ':datetime_' => $dateTimeStr]);
	
	// 5.11. if the posting goes wrong
	if($res === FALSE) {
		writeError($dbh->errorInfo());
		getComments(true, 'Something went wrong with the database', $commentData);
	}
	
	// 5.12. do the Post/Redirect/Get pattern. this prevents resubmit of the same form data when reloading the page
	header('Location: '. getSelf(['post'=>'1'], null), true, 303);
	echo '<a href="' . getSelf(['post'=>'1'], null) . '">Follow Post/Redirect/Get</a>';
	exit();
	
} else {
	// 6.A check for $_GET['post'] which is what we get back after the 303 succeeds after posting a comment.
	//    if this is the case we can let the user know the comment was successfully posted
	if(isset($_GET['post']) && $_GET['post'] === '1') {
		getComments(true, NULL, $commentData);
	} 
	// 6.B else we just show the comments without showing any message
	else {
		
		getComments(false, NULL, $commentData);
	}
}

// 7. handle the retrieval of comments from the database, and show the template
function getComments($posted, $postedErrorMessage, $commentData) {
	// 7.1. get the globals
	global $dbh, $postSlug;
	
	// 7.2. Depending upon the route, we have to query different things. 
	//      Usually, we have to grab the table with the contents of the comments
	$stmt = $dbh->prepare('SELECT commentor, comment, datetime_ FROM comments WHERE postslug = :postslug order by postid DESC');
	
	// 7.A statement is false code
	if($stmt === false) {
		$info = $dbh->errorInfo();
		
		// 7.3.1.A code for if the table does not exists yet
		if($info[2] === 'no such table: comments') {
			// create it
			$stmt = $dbh->query(
				'CREATE TABLE comments (
					postid INTEGER PRIMARY KEY NOT NULL,
					postslug text,
					commentor text,
					comment text,
					datetime_ text
				)'
			);
			
			// 7.3.2. show empty comments case
			drawComments([], false, NULL, $commentData);
			
			// 7.3.3. write an error if the create table statement is false
			if($stmt === false) {
				writeError($dbh->errorInfo());
			}
			
		} else {
			// 7.3.1.B write the error if the code is different, and show empty comments
			writeError($info);
			drawComments([], true, 'Something went wrong with the database', $commentData);
			return;
		}
	}
	// 7.B when the statement was successfull, AKA: proceed with getting the comments
	else {
		
		// 7.B.1 execute the statement and get the statementResult
		$stres = $stmt->execute([':postslug' => $postSlug]);
		
		// 7.B.A if the statement result is false
		if($stres === false) {
			// write the error
			writeError($dbh->errorInfo());
			// draw empty comments
			drawComments([], true, 'Something went wrong with the database', $commentData);
			return;
		} else {
			// 7.B.B fetch the results, and draw the comments
			$dataArr = $stmt->fetchALL();
			drawComments($dataArr, $posted, $postedErrorMessage, $commentData);
			return;
		}
	}
}


// 8. handle the entire template
function drawComments($comments, $posted, $postedErrorMessage, $commentData) {
global $postSlug;
// 8.1. draw the first stuff
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="These are the comments for the <?= $postSlug ?> page.">
<title>Comments for <?= $postSlug ?></title>
<style>
html,input,textarea{font-size:1rem;font-family:sans-serif;}
.comments {max-height:500px;overflow:auto;}
.comment{overflow:hidden;}
h1,h2 {
	margin-top: 0;
	margin-bottom: .5rem;
}
.user,.message{word-wrap:break-word;}
input,textarea{padding:4px;border:1px solid #999999;border-radius:3px;line-height:1;}
input[type=submit]{background:#ffbcff;border:0;color:black;padding:7px 10px;cursor:pointer;}
</style>
</head>
<body>
<?php

// 8.4. draw a message
if($posted) {
	echo '<div class="message">' . "\n";
	if($postedErrorMessage === NULL) {
		echo '	<strong>Comment Posted!</strong>';
	} else {
		echo '	<strong>ERROR: ' . $postedErrorMessage . '</strong>';
	}
	echo "</div>\n<br>";
}
?>
<a href="#postform">Skip to post comment</a>
<h1>Comments</h1>
<?php
// 8.2. draw $_SERVER and $_POST for debug
if(false) {
	echo '<pre>';
	var_export($_SERVER);
	echo "\n";
	var_export($_POST);
	/*echo "\n";
	$self = getSelf(null, ['bla']);
	echo $self;
	*/
	echo "\n";
	echo "\n";
	
	echo '</pre>';
}

// 8.3. draw comments
if(count($comments) == 0) {
	echo "<p>There are no comments yet.</p>\n";
} else {
	echo "<div class=\"comments\">\n";
	foreach($comments as $i => $comment) {
?>
	<article class="comment">
		
		
		<div class="user">
			From: <strong><?= htmlspecialchars($comment['commentor']); ?></strong>
		</div>
		<div class="message">
			<?= htmlspecialchars($comment['comment']); ?>
		</div>
		<div>
			<em><?= getAgoStr($comment['datetime_'], 3); ?></em>
		</div>
		<br>
	</article>
<?php
	}
	echo "</div>\n";
}

?>
<hr>
<h2>Post new comment</h2>
<form id="postform" method="POST" action="<?= getSelf(null, ['post']); ?>">
	<div>
		<label for="name"><strong>Name</strong></label>
		<br>
		<input type="text" id="name" name="name" placeholder="john doe" value="<?= $commentData['name'] ?>" required>
	</div>
	<br>
	<div>
		<label for="comment"><strong>Comment</strong></label>
		<br>
		<textarea name="comment" id="comment" cols="30" rows="4" placeholder="I really like this" required><?= $commentData['comment'] ?></textarea>
	</div>
	<input type="submit" value="Post the comment">
</form>
</body>
</html>
<?php
} // end of function drawComments

//
// helper functions
// 

// 9. get the url itself, with or without some url params
function getSelf($add, $remove) {
	// 1. get the protocol
	$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
	
	
	// 2. This next part is all about having support for adding and substracting $_GET keys from the url.
	{
		// 2.1 first convert the request uri into an array which contains 'path' and 'query'
		$requestParams = parse_url($_SERVER['REQUEST_URI']);
		
		// 2.2 check the query part and use parse_str to convert it into an associative array
		if(isset($requestParams['query'])) {
			$queryArr = [];
			parse_str($requestParams['query'], $queryArr);
		} else {
			$queryArr = [];
		}
		
		// 2.3. check for add, and add key value pairs to it
		if(isset($add)) {
			$queryArr = array_merge($queryArr, $add);
			
		}
		
		// 2.4. check for remove and remove stuff
		if(isset($remove)) {
			foreach($remove as $val) {
				unset($queryArr[$val]);
			}
		}
		
		// 2.5. turn the arr back into a query string
		$queryStr = http_build_query($queryArr);
		
		// 2.6. build up the url
		$requestUriStr = $requestParams['path'];
		
		// 2.6. fix the requestParams
		if($queryStr !== '') {
			$requestUriStr .= ('?' . $queryStr);
		}
		
	}
	
	// 3. return the current url, with or without certain uri components
	return $protocol . $_SERVER['HTTP_HOST'] . $requestUriStr;
}


// 11. returns a date time string, in format 12 jun 2022 - 15:44:23
function getDateTimeStr() {
	$curDateObj = new DateTime();
	$dateTime = $curDateObj->format('d M Y - H:i:s');
	return $dateTime;
}

// 12. convert the time into an ago string,
//     where it will spit out something like:
//     1 hour, 7 minutes and 20 seconds ago
function getAgoStr($datetime, $detailLevel) {
	
	// 1. get the dateInterval
	$now = new DateTime();
	$ago = new DateTime($datetime);
	$dateInterval = $now->diff($ago);
	
	// 2. define a map, with most of the data
	$map = [
		'year'   => $dateInterval->y,
		'month'  => $dateInterval->m,
		'week'   => NULL,
		'day'    => NULL,
		'hour'   => $dateInterval->h,
		'minute' => $dateInterval->i,
		'second' => $dateInterval->s,
	];
	
	// 3. add in week, and fixed day.
	$rawDays = $dateInterval->d;
	$weeks = (int)floor($rawDays / 7);
	$days = $rawDays % 7;
	
	$map['week'] = $weeks;
	$map['day'] = $days;
	
	// 4. loop though the array, fixing the name so it contains 
	//    s when there are more than 1
	//    second -> seconds
	//    and removing items with 0
	$arr = [];
	foreach($map as $name => $amounts) {
		if($amounts > 0) {
			
			$fixedName = $name;
			if($amounts > 1) {
				$fixedName .= 's';
			}
			
			$arr[$fixedName] = $amounts;
		}
	}
	
	// 5. slice it till the detail level (so we won't get more than it)
	$sliced = array_slice($arr, 0, $detailLevel);
	
	// 6. define an exception for just now
	if(count($sliced) == 0 || (count($sliced) == 1 && isset($sliced['seconds']))) {
		if($sliced['seconds'] < 5) {
			return 'just now';
		}
	}
	
	// 7. create the time string.
	//    adding ', ' for the inbetweens, except the last which will use ' and '
	$agoStr = '';
	$lastName = array_key_last($sliced);
	foreach ($sliced as $name => $amounts) {
		if($agoStr != '') {
			if($name != $lastName) {
				$agoStr .= ', ';
			} else {
				$agoStr .= ' and ';
			}
		}
		$agoStr .= $amounts . ' ' . $name;
	}
	$agoStr .= ' ago';
	
	// 8. return it
	return $agoStr;
}

// 12. write an error, this is for debugging of database errors.
function writeError($errorObj) {
	$dateTimeStr = getDateTimeStr();
	$errorStr = $dateTimeStr . ' :: ' .var_export($errorObj, true) . "\n\n";
	file_put_contents('errors.txt', $errorStr, FILE_APPEND);
}
?>
