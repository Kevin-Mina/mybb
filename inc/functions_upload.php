<?php
/**
 * MyBB 1.2
 * Copyright � 2007 MyBB Group, All Rights Reserved
 *
 * Website: http://www.mybboard.net
 * License: http://www.mybboard.net/about/license
 *
 * $Id$
 */


/**
 * Remove an attachment from a specific post
 *
 * @param int The post ID
 * @param string The posthash if available
 * @param int The attachment ID
 */
function remove_attachment($pid, $posthash, $aid)
{
	global $db, $mybb, $plugins;
	$aid = intval($aid);
	$posthash = $db->escape_string($posthash);
	if($posthash != "")
	{
		$query = $db->simple_select("attachments", "aid, attachname, thumbnail", "aid='{$aid}' AND posthash='{$posthash}'");
		$attachment = $db->fetch_array($query);
	}
	else
	{
		$query = $db->simple_select("attachments", "aid, attachname, thumbnail", "aid='{$aid}' AND pid='{$pid}'");
		$attachment = $db->fetch_array($query);
	}
	
	$plugins->run_hooks("remove_attachment_do_delete", $attachment);
	
	$db->delete_query("attachments", "aid='{$attachment['aid']}'");
	@unlink($mybb->settings['uploadspath']."/".$attachment['attachname']);
	if($attachment['thumbnail'])
	{
		@unlink($mybb->settings['uploadspath']."/".$attachment['thumbnail']);
	}

	$date_directory = explode('/', $attachment['attachname']);
	if(@is_dir($mybb->settings['uploadspath']."/".$date_directory[0]))
	{
		@rmdir($mybb->settings['uploadspath']."/".$date_directory[0]);
	}

	if($attachment['visible'] == 1 && $pid)
	{
		$post = get_post($pid);
		update_thread_counters($post['tid'], array("attachmentcount" => "-1"));
	}
}

/**
 * Remove all of the attachments from a specific post
 *
 * @param int The post ID
 * @param string The posthash if available
 */
function remove_attachments($pid, $posthash="")
{
	global $db, $mybb, $plugins;
	
	if($pid)
	{
		$post = get_post($pid);
	}
	$posthash = $db->escape_string($posthash);
	if($posthash != "" && !$pid)
	{
		$query = $db->simple_select("attachments", "*", "posthash='$posthash'");
	}
	else
	{
		$query = $db->simple_select("attachments", "*", "pid='$pid'");
	}

	$num_attachments = 0;
	while($attachment = $db->fetch_array($query))
	{
		if($attachment['visible'] == 1)
		{
			$num_attachments++;
		}
		
		$plugins->run_hooks("remove_attachments_do_delete", $attachment);
		
		$db->delete_query("attachments", "aid='".$attachment['aid']."'");
		
		@unlink($mybb->settings['uploadspath']."/".$attachment['attachname']);
		if($attachment['thumbnail'])
		{
			@unlink($mybb->settings['uploadspath']."/".$attachment['thumbnail']);
		}

		$date_directory = explode('/', $attachment['attachname']);
		if(@is_dir($mybb->settings['uploadspath']."/".$date_directory[0]))
		{
			@rmdir($mybb->settings['uploadspath']."/".$date_directory[0]);
		}
	}
	
	if($post['pid'])
	{
		update_thread_counters($post['tid'], array("attachmentcount" => "-{$num_attachments}"));
	}
}

/**
 * Remove any matching avatars for a specific user ID
 *
 * @param int The user ID
 * @param string A file name to be excluded from the removal
 */
function remove_avatars($uid, $exclude="")
{
	global $mybb, $plugins;
	
	$dir = opendir($mybb->settings['avataruploadpath']);
	if($dir)
	{
		while($file = @readdir($dir))
		{
			$plugins->run_hooks("remove_avatars_do_delete", $file);
			
			if(preg_match("#avatar_".$uid."\.#", $file) && is_file($mybb->settings['avataruploadpath']."/".$file) && $file != $exclude)
			{
				@unlink($mybb->settings['avataruploadpath']."/".$file);
			}
		}

		@closedir($dir);
	}
}

/**
 * Upload a new avatar in to the file system
 *
 * @param srray incoming FILE array, if we have one - otherwise takes $_FILES['avatarupload']
 * @param string User ID this avatar is being uploaded for, if not the current user
 * @return array Array of errors if any, otherwise filename of successful.
 */
function upload_avatar($avatar=array(), $uid=0)
{
	global $db, $mybb, $lang, $plugins;
	
	if(!$uid)
	{
		$uid = $mybb->user['uid'];
	}

	if(!$file['name'] || !$file['tmp_name'])
	{
		$avatar = $_FILES['avatarupload'];
	}

	if(!is_uploaded_file($avatar['tmp_name']))
	{
		$ret['error'] = $lang->error_uploadfailed;
		return $ret;
	}

	// Check we have a valid extension
	$ext = get_extension(my_strtolower($avatar['name']));
	if(!preg_match("#(gif|jpg|jpeg|jpe|bmp|png)$#i", $ext)) 
	{
		$ret['error'] = $lang->error_avatartype;
		return $ret;
	}
	
	$filename = "avatar_".$uid.".".$ext;
	$file = upload_file($avatar, $mybb->settings['avataruploadpath'], $filename);
	if($file['error'])
	{
		@unlink($mybb->settings['avataruploadpath']."/".$filename);		
		$ret['error'] = $lang->error_uploadfailed;
		return $ret;
	}	


	// Lets just double check that it exists
	if(!file_exists($mybb->settings['avataruploadpath']."/".$filename))
	{
		$ret['error'] = $lang->error_uploadfailed;
		@unlink($mybb->settings['avataruploadpath']."/".$filename);
		return $ret;
	}
	
	// Check if this is a valid image or not
	$img_dimensions = @getimagesize($mybb->settings['avataruploadpath']."/".$filename);
	if(!is_array($img_dimensions))
	{
		@unlink($mybb->settings['avataruploadpath']."/".$filename);
		$ret['error'] = $lang->error_uploadfailed;
		return $ret;
	}
	
	// Check avatar dimensions
	if($mybb->settings['maxavatardims'] != '')
	{
		list($maxwidth, $maxheight) = @explode("x", $mybb->settings['maxavatardims']);
		if(($maxwidth && $img_dimensions[0] > $maxwidth) || ($maxheight && $img_dimensions[1] > $maxheight))
		{
			// Automatic resizing enabled?
			if($mybb->settings['avatarresizing'] == "auto" || ($mybb->settings['avatarresizing'] == "user" && $mybb->input['auto_resize'] == 1))
			{
				require_once MYBB_ROOT."inc/functions_image.php";
				$thumbnail = generate_thumbnail($mybb->settings['avataruploadpath']."/".$filename, $mybb->settings['avataruploadpath'], $filename, $maxheight, $maxwidth);
				if(!$thumbnail['filename'])
				{
					$ret['error'] = sprintf($lang->error_avatartoobig, $maxwidth, $maxheight);
					$ret['error'] .= "<br /><br />".$lang->error_avatarresizefailed;
					@unlink($mybb->settings['avataruploadpath']."/".$filename);
					return $ret;				
				}
				else
				{
					// Reset filesize
					$avatar['size'] = filesize($mybb->settings['avataruploadpath']."/".$filename);
					// Reset dimensions
					$img_dimensions = @getimagesize($mybb->settings['avataruploadpath']."/".$filename);
				}
			}
			else
			{
				$ret['error'] = sprintf($lang->error_avatartoobig, $maxwidth, $maxheight);
				if($mybb->settings['avatarresizing'] == "user")
				{
					$ret['error'] .= "<br /<br />".$lang->error_avataruserresize;
				}
				@unlink($mybb->settings['avataruploadpath']."/".$filename);
				return $ret;
			}			
		}
	}
	
	// Next check the file size
	if($avatar['size'] > ($mybb->settings['avatarsize']*1024) && $mybb->settings['avatarsize'] > 0)
	{
		@unlink($mybb->settings['avataruploadpath']."/".$filename);
		$ret['error'] = $lang->error_uploadsize;
		return $ret;
	}	

	// Check a list of known MIME types to establish what kind of avatar we're uploading
	switch(my_strtolower($avatar['type']))
	{
		case "image/gif":
			$img_type =  1;
			break;
		case "image/jpeg":
		case "image/x-jpg":
		case "image/x-jpeg":
		case "image/pjpeg":
		case "image/jpg":
			$img_type = 2;
			break;
		case "image/png":
		case "image/x-png":
			$img_type = 3;
			break;
		default:
			$img_type = 0;
	}
	
	// Check if the uploaded file type matches the correct image type (returned by getimagesize)
	if($img_dimensions[2] != $img_type || $img_type == 0)
	{
		$ret['error'] = $lang->error_uploadfailed;
		@unlink($mybb->settings['avataruploadpath']."/".$filename);
		return $ret;		
	}
	// Everything is okay so lets delete old avatars for this user
	remove_avatars($user['uid'], $filename);

	$ret = array(
		"avatar" => $mybb->settings['avataruploadpath']."/".$filename,
		"width" => intval($img_dimensions[0]),
		"height" => intval($img_dimensions[1])
	);
	$plugins->run_hooks_by_ref("upload_avatar_end", $ret);
	return $ret;
}

/**
 * Upload an attachment in to the file system
 *
 * @param array Attachment data (as fed by PHPs $_FILE)
 * @return array Array of attachment data if successful, otherwise array of error data
 */
function upload_attachment($attachment)
{
	global $db, $theme, $templates, $posthash, $pid, $tid, $forum, $mybb, $lang, $plugins;
	
	$posthash = $db->escape_string($mybb->input['posthash']);

	if(isset($attachment['error']) && $attachment['error'] != 0)
	{
		$ret['error'] = $lang->error_uploadfailed.$lang->error_uploadfailed_detail;
		switch($attachment['error'])
		{
			case 1: // UPLOAD_ERR_INI_SIZE
				$ret['error'] .= $lang->error_uploadfailed_php1;
				break;
			case 2: // UPLOAD_ERR_FORM_SIZE
				$ret['error'] .= $lang->error_uploadfailed_php2;
				break;
			case 3: // UPLOAD_ERR_PARTIAL
				$ret['error'] .= $lang->error_uploadfailed_php3;
				break;
			case 4: // UPLOAD_ERR_NO_FILE
				$ret['error'] .= $lang->error_uploadfailed_php4;
				break;
			case 6: // UPLOAD_ERR_NO_TMP_DIR
				$ret['error'] .= $lang->error_uploadfailed_php6;
				break;
			case 7: // UPLOAD_ERR_CANT_WRITE
				$ret['error'] .= $lang->error_uploadfailed_php7;
				break;
			default:
				$ret['error'] .= sprintf($lang->error_uploadfailed_phpx, $attachment['error']);
				break;
		}
		return $ret;
	}
	
	if(!is_uploaded_file($attachment['tmp_name']) || empty($attachment['tmp_name']))
	{
		$ret['error'] = $lang->error_uploadfailed.$lang->error_uploadfailed_php4;
		return $ret;
	}
	
	$ext = get_extension($attachment['name']);
	// Check if we have a valid extension
	$query = $db->simple_select("attachtypes", "*", "extension='".$db->escape_string($ext)."'");
	$attachtype = $db->fetch_array($query);
	if(!$attachtype['atid'])
	{
		$ret['error'] = $lang->error_attachtype;
		return $ret;
	}
	
	// Check the size
	if($attachment['size'] > $attachtype['maxsize']*1024 && $attachtype['maxsize'] != "")
	{
		$ret['error'] = sprintf($lang->error_attachsize, $attachtype['maxsize']);
		return $ret;
	}

	// Double check attachment space usage
	if($mybb->usergroup['attachquota'] > 0)
	{
		$query = $db->simple_select("attachments", "SUM(filesize) AS ausage", "uid='".$mybb->user['uid']."'");
		$usage = $db->fetch_array($query);
		$usage = $usage['ausage']+$attachment['size'];
		if($usage > ($mybb->usergroup['attachquota']*1024))
		{
			$friendlyquota = get_friendly_size($mybb->usergroup['attachquota']*1024);
			$ret['error'] = sprintf($lang->error_reachedattachquota, $friendlyquota);
			return $ret;
		}
	}

	// Check if an attachment with this name is already in the post
	$query = $db->simple_select("attachments", "*", "filename='".$db->escape_string($attachment['name'])."' AND (posthash='$posthash' OR (pid='$pid' AND pid!='0'))");
	$prevattach = $db->fetch_array($query);
	if($prevattach['aid'])
	{
		$ret['error'] = $lang->error_alreadyuploaded;
		return $ret;
	}

	// Check if the attachment directory (YYYYMM) exists, if not, create it
	$month_dir = gmdate("Ym");
	if(!@is_dir($mybb->settings['uploadspath']."/".$month_dir))
	{
		@mkdir($mybb->settings['uploadspath']."/".$month_dir);
		// Still doesn't exist - oh well, throw it in the main directory
		if(!@is_dir($mybb->settings['uploadspath']."/".$month_dir))
		{
			$month_dir = '';
		}
	}    
	// All seems to be good, lets move the attachment!
	$filename = "post_".$mybb->user['uid']."_".TIME_NOW.".attach";
	$file = upload_file($attachment, $mybb->settings['uploadspath']."/".$month_dir, $filename);

	if($month_dir)
	{
		$filename = $month_dir."/".$filename;
	}
	
	if($file['error'])
	{
		$ret['error'] = $lang->error_uploadfailed.$lang->error_uploadfailed_detail;
		switch($file['error'])
		{
			case 1:
				$ret['error'] .= $lang->error_uploadfailed_nothingtomove;
				break;
			case 2:
				$ret['error'] .= $lang->error_uploadfailed_movefailed;
				break;
		}
		return $ret;
	}

	// Lets just double check that it exists
	if(!file_exists($mybb->settings['uploadspath']."/".$filename))
	{
		$ret['error'] = $lang->error_uploadfailed.$lang->error_uploadfailed_detail.$lang->error_uploadfailed_lost;
		return $ret;
	}

	// Generate the array for the insert_query
	$attacharray = array(
		"pid" => intval($pid),
		"posthash" => $posthash,
		"uid" => $mybb->user['uid'],
		"filename" => $db->escape_string($file['original_filename']),
		"filetype" => $db->escape_string($file['type']),
		"filesize" => intval($file['size']),
		"attachname" => $filename,
		"downloads" => 0,
		"dateuploaded" => TIME_NOW
	);

	// If we're uploading an image, check the MIME type compared to the image type and attempt to generate a thumbnail
	if($ext == "gif" || $ext == "png" || $ext == "jpg" || $ext == "jpeg" || $ext == "jpe")
	{
		// Check a list of known MIME types to establish what kind of image we're uploading
		switch(my_strtolower($file['type']))
		{
			case "image/gif":
				$img_type =  1;
				break;
			case "image/jpeg":
			case "image/x-jpg":
			case "image/x-jpeg":
			case "image/pjpeg":
			case "image/jpg":
				$img_type = 2;
				break;
			case "image/png":
			case "image/x-png":
				$img_type = 3;
				break;
			default:
				$img_type = 0;
		}

		// Check if the uploaded file type matches the correct image type (returned by getimagesize)
		$img_dimensions = @getimagesize($mybb->settings['uploadspath']."/".$filename);
		if(!is_array($img_dimensions) || $img_dimensions[2] != $img_type)
		{
			@unlink($mybb->settings['uploadspath']."/".$filename);
			$ret['error'] = $lang->error_uploadfailed;
			return $ret;		
		}		
		require_once MYBB_ROOT."inc/functions_image.php";
		$thumbname = str_replace(".attach", "_thumb.$ext", $filename);
		$thumbnail = generate_thumbnail($mybb->settings['uploadspath']."/".$filename, $mybb->settings['uploadspath'], $thumbname, $mybb->settings['attachthumbh'], $mybb->settings['attachthumbw']);
		
		if($thumbnail['filename'])
		{
			$attacharray['thumbnail'] = $thumbnail['filename'];
		}
		elseif($thumbnail['code'] == 4)
		{
			$attacharray['thumbnail'] = "SMALL";
		}
	}
	if($forum['modattachments'] == 1 && !is_moderator($forum['fid'], "", $mybb->user['uid']))
	{
		$attacharray['visible'] = 0;
	}
	else
	{
		$attacharray['visible'] = 1;
	}
	
	$plugins->run_hooks_by_ref("upload_attachment_do_insert", $attacharray);

	$db->insert_query("attachments", $attacharray);

	if($attacharray['pid'] > 0)
	{
		$post = get_post($attacharray['pid']);
		update_thread_counters($post['tid'], array("attachmentcount" => +1));
	}
	$aid = $db->insert_id();
	$ret['aid'] = $aid;
	return $ret;
}

/**
 * Actually move a file to the uploads directory
 *
 * @param array The PHP $_FILE array for the file
 * @param string The path to save the file in
 * @param string The filename for the file (if blank, current is used)
 */
function upload_file($file, $path, $filename="")
{
	global $plugins;
	
	if(empty($file['name']) || $file['name'] == "none" || $file['size'] < 1)
	{
		$upload['error'] = 1;
		return $upload;
	}

	if(!$filename)
	{
		$filename = $file['name'];
	}
	
	$upload['original_filename'] = preg_replace("#/$#", "", $file['name']); // Make the filename safe
	$filename = preg_replace("#/$#", "", $filename); // Make the filename safe
	$moved = @move_uploaded_file($file['tmp_name'], $path."/".$filename);
	
	if(!$moved)
	{
		$upload['error'] = 2;
		return $upload;
	}
	@chmod($path."/".$filename, 0777);
	$upload['filename'] = $filename;
	$upload['path'] = $path;
	$upload['type'] = $file['type'];
	$upload['size'] = $file['size'];
	$plugins->run_hooks_by_ref("upload_file_end", $upload);
	return $upload;
}
?>