<?php
/*
 *      Tiny Issue Tracker (TIT) v0.3b
 * 		SQLite based, Issue Tracker
 * 
 *		Forked into Jwalanta's TIT by acpmasquerade <acpmasquerade at gmail dot com>
 *		GNU GPL
 *		
 *		Original Credits 
 *      Jwalanta Shrestha <jwalanta at gmail dot com>
 * 		GNU GPL
 */


// ---------------------------------------------------------------------//
 // DO NOT EDIT ANYTHING BELOW, IF YOU ARE NOT SURE WHAT YOU ARE DOING //
 // THERE IS NO CONFIGURATION In This FILE //
 // ------------------------------------------------------------------//

// Application Location
$PUBROOT = realpath(dirname(__FILE__))."/";
$APPROOT = realpath($PUBROOT."../")."/";

// Read the main app configuration file
$APPCONFIG = parse_ini_file("{$APPROOT}config.ini", TRUE);

// APP Version hardcoded
$APPVERSION = "0.3b";

//  Location of SQLITE db file
//  (If the file doesn't exist, a new one will be created.
//  Make sure the folder is writable)
$SQLITE = @ $APPCONFIG["db"]["name"];

if(!$SQLITE){
	die("Configuration ERROR. Application Database not defined. ");
}else{
	$SQLITE = $APPROOT ."db/".$SQLITE;
}

// Project Title
$TITLE = @ $APPCONFIG["project"]["title"];
// "From Email address" for notifications
$EMAIL = @ $APPCONFIG["project"]["email"];

// Users from configuration
$USERS = @ $APPCONFIG["users"];

if(!$USERS){
	die("Configuration ERROR. Users not found");
}

foreach($USERS as $some_user_id=>$some_user_details){
	$USERS[$some_user_id] = explode(",", $some_user_details);
}

//
//	Select which notifications to send 

$NOTIFY["ISSUE_CREATE"] 	= TRUE;		// issue created
$NOTIFY["ISSUE_EDIT"] 		= TRUE;		// issue edited
$NOTIFY["ISSUE_DELETE"] 	= TRUE;		// issue deleted
$NOTIFY["ISSUE_STATUS"] 	= TRUE;		// issue status change (solved / unsolved)
$NOTIFY["ISSUE_PRIORITY"] 	= TRUE;		// issue status change (solved / unsolved)
$NOTIFY["COMMENT_CREATE"] 	= TRUE;		// comment post


///////////////////////////////////////////////////////////////////////
////// DO NOT EDIT BEYOND THIS IF YOU DONT KNOW WHAT YOU'RE DOING /////
///////////////////////////////////////////////////////////////////////

// Here we go...
session_start();

// debug function
// @acpmasquerade
function mdie($var){
    echo "<pre>";
    print_r($var);
    die();
}

// @acpmasquerade
function ndie($var){
    echo "<pre>";
    print_r($var);
    echo "</pre>";
}

// check for login post
if (isset($_POST["login"])){
	$n = check_credentials($_POST["u"],md5($_POST["p"]));
	if ($n>=0){
		$_SESSION['u']=$USERS[$n][0];	// username
		$_SESSION['p']=$USERS[$n][1];	// password
		$_SESSION['e']=$USERS[$n][2];	// email
		
		header("Location: {$_SERVER['PHP_SELF']}");
	}
	else header("Location: {$_SERVER['PHP_SELF']}?loginerror");
}

// check for logout 
if (isset($_GET['logout'])){
	$_SESSION['u']='';	// username
	$_SESSION['p']='';	// password
	$_SESSION['e']='';	// email
	
	header("Location: {$_SERVER['PHP_SELF']}");	
}

if (isset($_GET['loginerror'])) $message = "Invalid username or password";
$login_html = "<h2>{$TITLE} - Issue Tracker</h2><p>{$message}</p><form method='POST'>
			   <label>Username</label><input type='text' name='u' />
			   <label>Password</label><input type='password' name='p' />
			   <label></label><input type='submit' name='login' value='Login' />
			   </form>
			   ";

// show login page on bad credential
if (check_credentials($_SESSION['u'], $_SESSION['p'])==-1){
    // Load the RockingSoon API and create some design type page.
    include_once $APPROOT . "rockingsoon.php";

    $rocking_soon_data = array();
    $rocking_soon_data["logo"] = "TIT - {$APPVERSION}";
    $rocking_soon_data["tagline"] = "Login";
    $rocking_soon_data["footer"] = "powered by Tiny Issue Tracker - {$APPVERSION}";

    $rocking_soon_template = rocking_soon($rocking_soon_data);

    print $rocking_soon_template->header;
    print $login_html;
    print $rocking_soon_template->footer;
    die("\n");
}

// Check if db exists
if (!($db = sqlite_open($SQLITE, 0666, $sqliteerror))) {
    die($sqliteerror);
}

// create tables if not exist
@sqlite_query($db, 'CREATE TABLE issues (id INTEGER PRIMARY KEY, title TEXT, description TEXT, user TEXT, status INTEGER, priority INTEGER, notify_emails INTEGER, entrytime DATETIME)');
@sqlite_query($db, 'CREATE TABLE comments (id INTEGER PRIMARY KEY, issue_id INTEGER, user TEXT, description TEXT, entrytime DATETIME)');
@sqlite_query($db, 'CREATE TABLE attachments (id INTEGER PRIMARY KEY, issue_id INTEGER, user TEXT, filename TEXT, filepath TEXT, mimetype TEXT, entrytime DATETIME)');

if (isset($_GET["id"])){
	// show issue #id
	
	$id=sqlite_escape_string($_GET['id']);
	$issue = sqlite_array_query($db, "SELECT id, title, description, user, status, priority, notify_emails, entrytime FROM issues WHERE id='$id'");
	$comments = sqlite_array_query($db, "SELECT id, user, description, entrytime FROM comments WHERE issue_id='$id' ORDER BY entrytime ASC");
    $attachments = sqlite_array_query($db, "SELECT id, issue_id, user, filename, filepath, mimetype, entrytime FROM attachments WHERE issue_id='$id'");    
}

// if no issue found, go to list mode
if (count($issue)==0){
	
	unset($issue, $comments);
	// show all issues

	if (isset($_GET["resolved"])) {
		$issues = sqlite_array_query($db, "SELECT id, title, description, user, status, priority, notify_emails, entrytime FROM issues WHERE status=1 ORDER BY priority, entrytime DESC");
    }
	else {
		$issues = sqlite_array_query($db, "SELECT id, title, description, user, status, priority, notify_emails, entrytime FROM issues WHERE (status=0 OR status IS NULL) ORDER BY priority, entrytime DESC");
    }

    // loop through the issues
    $issue_ids = array();
    foreach($issues as $some_issue){
        $issue_ids[] = $some_issue["id"];
    }

    $issue_ids_csv = implode(",", $issue_ids);

    $attachments_raw = sqlite_array_query($db, "SELECT count(*) files, issue_id FROM attachments WHERE issue_id IN ('$issue_ids_csv') group by issue_id ");

    $attachments = array();
    foreach($attachments_raw as $attachment_row){
        $attachments[$attachment_row["issue_id"]] = $attachment_row["files"];
    }

	$mode="list";
}
else {
	$issue = $issue[0];
	$mode="issue";
}

//
// PROCESS ACTIONS
//

// Create / Edit issue
if (isset($_POST["createissue"])){

    // Issue fields
	$id=sqlite_escape_string($_POST['id']);
	$title=sqlite_escape_string($_POST['title']);
	$description=sqlite_escape_string($_POST['description']);

    // Attachments
    $attachments = $_FILES["uploaded"];

    $attachments_accepted = array();
    $attachments_allowed_mimes = array("image", "text");
    
    foreach($attachments["name"] as $attachment_no => $attachment_filename){
        if($attachments["error"][$attachment_no] > 0){
            continue;
        }else{
            // check the mime type
            $attachment_mime_type = explode("/", $attachments["type"][$attachment_no]);
            $attachment_mime_type_prefix = strtolower($attachment_mime_type[0]);

            if(in_array($attachment_mime_type_prefix, $attachments_allowed_mimes)){
                $attachments_accepted[] = $attachment_no;
            }else{
                // skip the attachment, and mark it as invalid. 
            }
        }
    }

	$user=$_SESSION['u'];
	$now=date("Y-m-d H:i:s");
	
	// gather all emails
	$emails=array();
	for ($i=0;$i<count($USERS);$i++){
		if ($USERS[$i][2]!='') $emails[] = $USERS[$i][2];
	}
	$notify_emails = implode(",",$emails);
	
	if ($id==''){
		$query = "INSERT INTO issues (title, description, user, priority, notify_emails, entrytime) values('$title','$description','$user','2','$notify_emails','$now')"; // create
    }else{
		$query = "UPDATE issues SET title='$title', description='$description' WHERE id='$id'"; // edit
    }

	if (trim($title)!='') {		// title cant be blank
		@sqlite_query($db, $query);
		if ($id==''){
			// created
			$id=sqlite_last_insert_rowid($db);
			if ($NOTIFY["ISSUE_CREATE"]) 
				notify(	$id,
						"[$TITLE] New Issue Created",
						"New Issue Created by {$_SESSION['u']}\r\nTitle: $title\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");			
		}
		else{
			// edited
			if ($NOTIFY["ISSUE_EDIT"]) 
				notify(	$id,
						"[$TITLE] Issue Edited",
						"Issue edited by {$_SESSION['u']}\r\nTitle: $title\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");			
		}

        // proceed with attachments
        foreach($attachments_accepted as $attachment_no){

            $attachment_original_filename = $attachments["name"][$attachment_no];
            $attachment_slug = preg_replace("/[^0-9a-zA-Z_\.\-]+/", "", $attachment_original_filename);
            
            $attachment_tempname = $attachments["tmp_name"][$attachment_no];
            $attachment_newfilename = time()."_".md5($attachment_tempname)."_".$attachment_slug;
                    
            move_uploaded_file( $attachment_tempname , $APPROOT ."uploads/" . $attachment_newfilename );
            
            $attachment_filename = $attachments["name"][$attachment_no];
            $attachment_filepath = $attachment_newfilename;
            $attachment_mimetype = $attachments["type"][$attachment_no];

            //(id INTEGER PRIMARY KEY, issue_id INTEGER, user TEXT, filename TEXT, filepath TEXT, mimetype TEXT, entrytime DATETIME)
            $attachment_query = "INSERT INTO attachments (issue_id, user, filename, filepath, mimetype, entrytime )
                    values('$id', '$user','$attachment_filename','$attachment_filepath','$attachment_mimetype', '$now')"; // create

            @sqlite_query($db, $attachment_query);
        }
	}
	
	header("Location: {$_SERVER['PHP_SELF']}");

}

// Delete issue
if (isset($_GET["deleteissue"])){
	$id=sqlite_escape_string($_GET['id']);
	$title=get_col($id,"issues","title");
	
	// only the issue creator or admin can delete issue
	if ($_SESSION['u']=='admin' || $_SESSION['u']==get_col($id,"issues","user")){
		@sqlite_query($db, "DELETE FROM issues WHERE id='$id'");
		@sqlite_query($db, "DELETE FROM comments WHERE issue_id='$id'");
		
		if ($NOTIFY["ISSUE_DELETE"]) 
			notify(	$id,
					"[$TITLE] Issue Deleted",
					"Issue deleted by {$_SESSION['u']}\r\nTitle: $title");	
	}
	header("Location: {$_SERVER['PHP_SELF']}");
	
}

// Change Priority
if (isset($_GET["changepriority"])){
	$id=sqlite_escape_string($_GET['id']);
	$priority=sqlite_escape_string($_GET['priority']);
	if ($priority>=1 && $priority<=3) @sqlite_query($db, "UPDATE issues SET priority='$priority' WHERE id='$id'");
	
	if ($NOTIFY["ISSUE_PRIORITY"]) 
		notify(	$id,
				"[$TITLE] Issue Priority Changed",
				"Issue Priority changed by {$_SESSION['u']}\r\nTitle: ".get_col($id,"issues","title")."\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");
	
	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

// Mark as solved
if (isset($_POST["marksolved"])){
	$id=sqlite_escape_string($_POST['id']);
	@sqlite_query($db, "UPDATE issues SET status='1' WHERE id='$id'");
	
	if ($NOTIFY["ISSUE_STATUS"]) 
		notify(	$id,
				"[$TITLE] Issue Marked as Solved",
				"Issue marked as solved by {$_SESSION['u']}\r\nTitle: ".get_col($id,"issues","title")."\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");
	
	header("Location: {$_SERVER['PHP_SELF']}");
}

// Mark as unsolved
if (isset($_POST["markunsolved"])){
	$id=sqlite_escape_string($_POST['id']);
	@sqlite_query($db, "UPDATE issues SET status='0' WHERE id='$id'");

	if ($NOTIFY["ISSUE_STATUS"]) 
		notify(	$id,
				"[$TITLE] Issue Marked as Unsolved",
				"Issue marked as unsolved by {$_SESSION['u']}\r\nTitle: ".get_col($id,"issues","title")."\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");
	
	header("Location: {$_SERVER['PHP_SELF']}");
}

// Unwatch
if (isset($_POST["unwatch"])){
	$id=sqlite_escape_string($_POST['id']);
	unwatch($id);	// remove from watch list
	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

// Watch
if (isset($_POST["watch"])){
	$id=sqlite_escape_string($_POST['id']);
	watch($id);		// add to watch list
	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}


// Create Comment
if (isset($_POST["createcomment"])){

	$issue_id=sqlite_escape_string($_POST['issue_id']);
	$description=sqlite_escape_string($_POST['description']);
	$user=$_SESSION['u'];
	$now=date("Y-m-d H:i:s");	
	
	if (trim($description)!=''){
		$query = "INSERT INTO comments (issue_id, description, user, entrytime) values('$issue_id','$description','$user','$now')"; // create
		sqlite_query($db, $query);
	}
	
	if ($NOTIFY["COMMENT_CREATE"]) 
		notify(	$id,
				"[$TITLE] New Comment Posted",
				"New comment posted by {$_SESSION['u']}\r\nTitle: ".get_col($id,"issues","title")."\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$issue_id");
	
	header("Location: {$_SERVER['PHP_SELF']}?id=$issue_id");
	
}

// Delete Comment
if (isset($_GET["deletecomment"])){
	$id=sqlite_escape_string($_GET['id']);
	$cid=sqlite_escape_string($_GET['cid']);
	
	// only comment poster or admin can delete comment
	if ($_SESSION['u']=='admin' || $_SESSION['u']==get_col($cid,"comments","user"))	
		sqlite_query($db, "DELETE FROM comments WHERE id='$cid'");
	
	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

// Download File
if (isset($_GET["file"])){
    $file_requested = sqlite_escape_string($_GET["file"]);
    $file_information = sqlite_array_query($db, "SELECT * from attachments WHERE filepath = '{$file_requested}' LIMIT 1");

    if(!$file_information){
        die("File Not Found");
    }else{        
        $file_information = $file_information[0];
        $file_mimetype = $file_information["mimetype"];
        header("Content-Type: {$file_mimetype}");
        echo file_get_contents($APPROOT ."uploads/".$file_information["filepath"]);
        die();
    }
}

//
// 	FUNCTIONS 
//

// check credentials, returns -1 if not okay
function check_credentials($u, $p){
	global $USERS;
	
	$n=0;
	foreach ($USERS as $user){
		if ($user[0]==$u && $user[1]==$p) return $n;
		$n++;
	}
	return -1;
}

// get column from some table with $id
function get_col($id, $table, $col){
	global $db;
	$result = sqlite_array_query($db, "SELECT $col FROM $table WHERE id='$id'");
	return $result[0][$col];		
}

// notify via email
function notify($id, $subject, $body){
	global $db;
	$result = sqlite_array_query($db, "SELECT notify_emails FROM issues WHERE id='$id'");
	$to = $result[0]['notify_emails'];
	
	if ($to!=''){
		global $EMAIL;
		$headers = "From: $EMAIL" . "\r\n" . 'X-Mailer: PHP/' . phpversion();		
		
		mail($to, $subject, $body, $headers);	// standard php mail, hope it passes spam filter :)
	}
	
}

// start watching an issue
function watch($id){
	global $db;
	if ($_SESSION['e']=='') return;
	
	$result = sqlite_array_query($db, "SELECT notify_emails FROM issues WHERE id='$id'");
	$notify_emails = $result[0]['notify_emails'];
	
	if ($notify_emails!=''){
		$emails = explode(",",$notify_emails);
		$emails[] = $_SESSION['e'];
		
		$emails = array_unique($emails);
		$notify_emails = implode(",",$emails);
		
		sqlite_query($db, "UPDATE issues SET notify_emails='$notify_emails' WHERE id='$id'");
	}
}

// unwatch an issue
function unwatch($id){
	global $db;
	if ($_SESSION['e']=='') return;
	
	$result = sqlite_array_query($db, "SELECT notify_emails FROM issues WHERE id='$id'");
	$notify_emails = $result[0]['notify_emails'];
	
	if ($notify_emails!=''){
		$emails = explode(",",$notify_emails);
		
		$final_email_list=array();
		foreach ($emails as $email){
			if ($email!=$_SESSION['e'] && $email!='') $final_email_list[] = $email;
		}
		$notify_emails = implode(",",$final_email_list);
		
		sqlite_query($db, "UPDATE issues SET notify_emails='$notify_emails' WHERE id='$id'");
	}	
}

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
  "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
	<title><?php echo $TITLE, " - Issue Tracker"; ?></title>
	<meta http-equiv="content-type" content="text/html;charset=utf-8" />
	<style>
		body { font-family: sans-serif; font-size: 11px; background-color: #aaa;}
		a, a:visited{color:#004989; text-decoration:none;}
		a:hover{color: #666; text-decoration: underline;}
		label{ display: block; font-weight: bold;}
		table{border-collapse: collapse;}
		th{text-align: left; background-color: #f2f2f2;}
		tr:hover{background-color: #f0f0f0;}
		#menu{float: right;}
		#container{width: 760px; margin: 0 auto; padding: 20px; background-color: #fff;}
		#footer{padding:10px 0 0 0; margin-top: 20px; text-align: center; border-top: 1px solid #ccc;}
		#create{padding: 15px; background-color: #f2f2f2;}
		.issue{padding:10px 20px; margin: 10px 0; background-color: #f2f2f2;}
		.comment{padding:5px 10px 10px 10px; margin: 10px 0; border: 1px solid #ccc;}
		.comment-meta{color: #666;}
        .attachments_wrapper{background:white;padding:10px;}
		.p1, .p1 a{color: red;}
		.p3, .p3 a{color: #666;}
		.hide{display:none;}
		.left{float: left;}
		.right{float: right;}
		.clear{clear:both;}
		
	</style>
</head>
<body>
<div id='container'>
	<div id="menu">
		<a href="<?php echo $_SERVER['PHP_SELF']; ?>" alt="Active Issues">Active Issues</a> | 
		<a href="<?php echo $_SERVER['PHP_SELF']; ?>?resolved" alt="Resolved Issues">Resolved Issues</a> | 
		<a href="<?php echo $_SERVER['PHP_SELF']; ?>?logout" alt="Logout">Logout [<?php echo $_SESSION['u']; ?>]</a>
	</div>

	<h1><?php echo $TITLE; ?></h1>

	<h2><a href="#" onclick="document.getElementById('create').className='';document.getElementById('title').focus();"><?php echo ($issue['id']==''?"Create":"Edit"); ?> Issue</a></h2>
	<div id="create" class='<?php echo isset($_GET['editissue'])?'':'hide'; ?>'>
		<a href="#" onclick="document.getElementById('create').className='hide';" style="float: right;">[Close]</a>
		<form method="POST" enctype="multipart/form-data">
			<input type="hidden" name="id" value="<?php echo $issue['id']; ?>" />
			<label>Title</label><input type="text" size="50" name="title" id="title" value="<?php echo stripslashes($issue['title']); ?>" /> 
			<label>Description</label><textarea name="description" rows="5" cols="50"><?php echo stripslashes($issue['description']); ?></textarea>
            <label>Attachments</label>

            <?php if($attachments): ?>
            <ul>
                <?php foreach($attachments as $a): ?>
                <li>
                    <?php echo $a["filename"]; ?>
                </li>
                <?php endforeach;?>
            </ul>
            <?php endif; ?>
            
            <input name="uploaded[]" type="file" /><br />
            <input name="uploaded[]" type="file" /><br />
            <input name="uploaded[]" type="file" /><br />
            <input name="uploaded[]" type="file" /><br />
			<label></label><input type="submit" name="createissue" value="<?php echo ($issue['id']==''?"Create":"Edit"); ?>" />
		</form>
	</div>
	
	<?php if ($mode=="list"): ?>
	<div id="list">
	<h2><?php if (isset($_GET['resolved'])) echo "Resolved "; ?>Issues</h2>
		<table border=1 cellpadding=5 width="100%">
			<tr>
				<th width="5%">S.No.</th>
                <th width="5%">Files</th>
				<th width="45%">Issue</th>
				<th width="15%">Created by</th>                
				<th width="15%">Date</th>
				<th width="5%">Watch</th>
				<th width="15%">Actions</th>
			</tr>
		
			<?php
			$count=1;
			foreach ($issues as $issue){
				echo "<tr class='p{$issue['priority']}'>\n"; 
				echo "<td>".$count++."</a></td>\n";
                if($attachments[$issue["id"]]){
                    echo "<td>{$attachments[$issue["id"]]}</td>\n";
                }else{
                    echo "<td>-</td>\n";
                }
				echo "<td><a href='?id={$issue['id']}'>{$issue['title']}</a></td>\n";
				echo "<td>{$issue['user']}</td>\n";
				echo "<td>{$issue['entrytime']}</td>\n";
				echo "<td>".(strpos($issue['notify_emails'],$_SESSION['e'])!==FALSE?"✔":"")."</td>\n";
				echo "<td><a href='?editissue&id={$issue['id']}'>Edit</a>";
				if ($_SESSION['u']=='admin' || $_SESSION['u']==$issue['user']) echo " | <a href='?deleteissue&id={$issue['id']}' onclick='return confirm(\"Are you sure? All comments will be deleted too.\");'>Delete</a>";
				echo "</td>\n";
				echo "</tr>\n";
			}
			
			?>

		</table>
	</div>
	<?php endif; ?>
	
	<?php if ($mode=="issue"): ?>	
	<div id="show">
		<div class="issue">
			<h2><?php echo htmlentities(stripslashes($issue['title']),ENT_COMPAT,"UTF-8"); ?></h2>
			<p><?php echo str_replace("\n","<br />",htmlentities(stripslashes($issue['description']),ENT_COMPAT,"UTF-8")); ?></p>
            <?php if($attachments): ?>
            <hr />
            <div class="attachments_wrapper">
                <strong>Attachments</strong>
                <ul class="attachments">
                    <?php foreach($attachments as $a): ?>
                    <li>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>?file=<?php echo $a["filepath"];?>"><?php echo $a["filename"];?></a>
                    </li>
                    <?php endforeach;?>
                </ul>
            </div>
            <?php endif; ?>
		</div>
		<div class='left'>
			Priority <select name="priority" onchange="location='<?php echo $_SERVER['PHP_SELF']; ?>?changepriority&id=<?php echo $issue['id']; ?>&priority='+this.value">
				<option value="1"<?php echo ($issue['priority']==1?"selected":""); ?>>High</option>
				<option value="2"<?php echo ($issue['priority']==2?"selected":""); ?>>Medium</option>
				<option value="3"<?php echo ($issue['priority']==3?"selected":""); ?>>Low</option>
				
			</select>
		</div>
		<div class='left'>
			<form method="POST">
				<input type="hidden" name="id" value="<?php echo $issue['id']; ?>" />
				<input type="submit" name="mark<?php echo $issue['status']==1?"unsolved":"solved"; ?>" value="Mark as <?php echo $issue['status']==1?"Unsolved":"Solved"; ?>" />
				<?php 
					if (strpos($issue['notify_emails'],$_SESSION['e'])===FALSE)
						echo "<input type='submit' name='watch' value='Watch' />\n";
					else
						echo "<input type='submit' name='unwatch' value='Unwatch' />\n";
				
				?>
			</form>
		</div>	
		<div class='clear'></div>
		
		<div id="comments">
			<?php
			if (count($comments)>0) echo "<h3>Comments</h3>\n";
			foreach ($comments as $comment){
				echo "<div class='comment'><p>".str_replace("\n","<br />",htmlentities(stripslashes($comment['description']),ENT_COMPAT,"UTF-8"))."</p>";
				echo "<div class='comment-meta'><em>{$comment['user']}</em> on <em>{$comment['entrytime']}</em> ";
				if ($_SESSION['u']=='admin' || $_SESSION['u']==$comment['user']) echo "<span class='right'><a href='{$_SERVER['PHP_SELF']}?deletecomment&id={$issue['id']}&cid={$comment['id']}' onclick='return confirm(\"Are you sure?\");'>Delete</a></span>";
				echo "</div></div>\n";
			}
			?>
			<div id="comment-create">
				<h4>Post a comment</h4>
				<form method="POST">
					<input type="hidden" name="issue_id" value="<?php echo $issue['id']; ?>" />
					<textarea name="description" rows="5" cols="50"></textarea>
					<label></label>
					<input type="submit" name="createcomment" value="Comment" />
				</form>			
			</div>		
		</div>
	</div>
	<?php endif; ?>
	<div id="footer">
		Powered by <a href="http://acpmasquerade.github.com/tit/" alt="Tiny Issue Tracker" target="_blank">Tiny Issue Tracker</a>
	</div>
</div>
</body>
</html>
