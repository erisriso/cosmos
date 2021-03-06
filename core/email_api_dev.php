<?php
# COSMOS - a php based candidatetracking system

# 
# 

# COSMOS is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# COSMOS is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with COSMOS.  If not, see <http://www.gnu.org/licenses/>.

	# --------------------------------------------------------
	# $Id: email_api.php,v 1.139.2.3 2007-10-22 07:40:59 vboctor Exp $
	# --------------------------------------------------------

	$t_core_dir = dirname( __FILE__ ).DIRECTORY_SEPARATOR;

	define( 'PHPMAILER_PATH', $t_core_dir . 'phpmailer' . DIRECTORY_SEPARATOR );
	
	require_once( $t_core_dir . 'current_user_api.php' );
	require_once( $t_core_dir . 'candidate_api.php' );
	require_once( $t_core_dir . 'custom_field_api.php' );
	require_once( $t_core_dir . 'string_api.php' );
	require_once( $t_core_dir . 'user_api.php' );
	require_once( $t_core_dir . 'history_api.php' );
	require_once( $t_core_dir . 'email_queue_api.php' );
	require_once( $t_core_dir . 'relationship_api.php' );
	require_once( $t_core_dir . 'disposable' . DIRECTORY_SEPARATOR . 'disposable.php' );
	require_once( PHPMAILER_PATH . 'class.phpmailer.php' );

	# reusable object of class SMTP
	$g_phpMailer_smtp = null;

	###########################################################################
	# Email API
	###########################################################################

	# Use a simple perl regex for valid email addresses.  This is not a complete regex,
	# as it does not cover quoted addresses or domain literals, but it is simple and
	# covers the vast majority of all email addresses without being overly complex.
	# Callers must surround this with appropriate delimiters with case insentive options.
	function email_regex_simple() {
		return "(([a-z0-9!#*+\/=?^_{|}~-]+(?:\.[a-z0-9!#*+\/=?^_{|}~-]+)*)" . 				# recipient
			"\@((?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?))";	# @domain
	}

	# --------------------
	# Return a perl compatible regular expression that will
	#  match a valid email address as per RFC 822 (approximately)
	#
	# The regex will provide too matched groups: the first will be the
	#  local part (or mailbox name) and the second will be the domain
	function email_get_rfc822_regex() {
		# Build up basic RFC 822 BNF definitions.

		# list of the special characters: ( ) < > @ , ; : \ " . [ ]
		$t_specials = '\(\)\<\>\@\,\;\:\\\"\.\[\]';
		# the space character
		$t_space    = '\040';
		# valid characters in a quoted string
		$t_char     = '\000-\177';
		# control characters
		$t_ctl      = '\000-\037\177';

		# a chunk of quoted text (anything except " \ \r are valid)
		$t_qtext_re = '[^"\\\r]+';
		# match any valid character preceded by a backslash ( mostly for \" )
		$t_qpair_re = "\\\\[$t_char]";

		# a complete quoted string - " characters with valid characters or
		#  backslash-escaped characters between them
		$t_quoted_string_re = "(?:\"(?:$t_qtext_re|$t_qpair_re)*\")";

		# an unquoted atom (anything that isn't a control char, a space, or a
		#  special char)
		$t_atom_re  = "(?:[^$t_ctl$t_space$t_specials]+)";

		# a domain ref is an atom
		$t_domain_ref_re = $t_atom_re;

		# the characters in a domain literal can be anything except: [ ] \ \r
		$t_dtext_re = "[^\\[\\]\\\\\\r]";
		# a domain-literal is a sequence of characters or escaped pairs inside
		#  square brackets
		$t_domain_literal_re = "\\[(?:$t_dtext_re|$t_qpair_re)*\\]";
		# a subdomain is a domain ref or a domain literal
		$t_sub_domain_re = "(?:$t_domain_ref_re|$t_domain_literal_re)";
		# a domain is at least one subdomain, with optional further subdomains
		#  separated by periods.  eg: '[1.2.3.4]' or 'foo.bar'
		$t_domain_re = "$t_sub_domain_re(?:\.$t_sub_domain_re)*";

		# a word is either quoted string or an atom
		$t_word_re = "(?:$t_atom_re|$t_quoted_string_re)";

		# the local part of the address spec (the mailbox name)
		#  is one or more words separated by periods
		$t_local_part_re = "$t_word_re(?:\.$t_word_re)*";

		# the address spec is made up of a local part, and @ symbol,
		#  and a domain
		$t_addr_spec_re = "/^($t_local_part_re)\@($t_domain_re)$/";

		return $t_addr_spec_re;
	}
	# --------------------
	# check to see that the format is valid and that the mx record exists
	function email_is_valid( $p_email ) {
		# if we don't validate then just accept
		if ( OFF == config_get( 'validate_email' ) ) {
			return true;
		}

		if ( is_blank( $p_email ) && ON == config_get( 'allow_blank_email' ) ) {
			return true;
		}

		# Use a regular expression to check to see if the email is in valid format
		#  x-xx.xxx@yyy.zzz.abc etc.
		if ( preg_match( email_get_rfc822_regex(), $p_email, $t_check ) ) {
			$t_local = $t_check[1];
			$t_domain = $t_check[2];

			# see if we're limited to one domain
			if ( ON == config_get( 'limit_email_domain' ) ) {
				if ( 0 != strcasecmp( $t_limit_email_domain, $t_domain ) ) {
					return false;
				}
			}

			if ( preg_match( '/\\[(\d+)\.(\d+)\.(\d+)\.(\d+)\\]/', $t_domain, $t_check ) ) {
				# Handle domain-literals of the form '[1.2.3.4]'
				#  as long as each segment is less than 255, we're ok
				if ( $t_check[1] <= 255 &&
					 $t_check[2] <= 255 &&
					 $t_check[3] <= 255 &&
					 $t_check[4] <= 255 ) {
					return true;
				}
			} else if ( ON == config_get( 'check_mx_record' ) ) {
				# Check for valid mx records
				if ( getmxrr( $t_domain, $temp ) ) {
					return true;
				} else {
					$host = $t_domain . '.';

					# for no mx record... try dns check
					if ( checkdnsrr( $host, 'ANY' ) ) {
						return true;
					}
				}
			} else {
				# Email format was valid but did't check for valid mx records
				return true;
			}
		}

		# Everything failed.  The email is invalid
		return false;
	}
	# --------------------
	# Check if the email address is valid
	#  return true if it is, trigger an ERROR if it isn't
	function email_ensure_valid( $p_email ) {
		if ( !email_is_valid( $p_email ) ) {
			trigger_error( ERROR_EMAIL_INVALID, ERROR );
		}
	}

	# --------------------
	# Check if the email address is disposable
	function email_is_disposable( $p_email ) {
		return DisposableEmailChecker::is_disposable_email( $p_email ); 
	}

	# --------------------
	# Check if the email address is disposable
	function email_ensure_not_disposable( $p_email ) {
		if ( email_is_disposable( $p_email ) ) {
			trigger_error( ERROR_EMAIL_DISPOSABLE, ERROR );
		}
	}

	# --------------------
	# email_notify_flag
	# Get the value associated with the specific action and flag.
	# For example, you can get the value associated with notifying "admin"
	# on action "new", i.e. notify administrators on new candidates which can be
	# ON or OFF.
	function email_notify_flag( $action, $flag ) {
		$t_notify_flags = config_get( 'notify_flags' );
		$t_default_notify_flags = config_get( 'default_notify_flags' );
		if ( isset ( $t_notify_flags[$action][$flag] ) ) {
			return $t_notify_flags[$action][$flag];
		} elseif ( isset ( $t_default_notify_flags[$flag] ) ) {
			return $t_default_notify_flags[$flag];
		}

		return OFF;
	}

	# @@@ yarick123: email_collect_recipients(...) will be completely rewritten to provide additional
	#     information such as language, user access,..
	# @@@ yarick123:sort recipients list by language to reduce switches between different languages
	function email_collect_recipients( $p_candidate_id, $p_notify_type ) {
		$c_candidate_id = db_prepare_int( $p_candidate_id );

		$t_recipients = array();

		# add Reporter
		if ( ON == email_notify_flag( $p_notify_type, 'reporter' ) ) {
			$t_reporter_id = candidate_get_field( $p_candidate_id, 'reporter_id' );
			$t_recipients[$t_reporter_id] = true;
			log_event( LOG_EMAIL_RECIPIENT, "candidate=$p_candidate_id, add reporter=$t_reporter_id" );
		}

		# add Handler
		if ( ON == email_notify_flag( $p_notify_type, 'handler' )) {
			$t_handler_id = candidate_get_field( $p_candidate_id, 'handler_id' );
			
			if ( $t_handler_id > 0 ) {
				$t_recipients[$t_handler_id] = true;
				log_event( LOG_EMAIL_RECIPIENT, "candidate=$p_candidate_id, add handler=$t_handler_id" );
			}
		}

		$t_project_id = candidate_get_field( $p_candidate_id, 'project_id' );

		# add users monitoring the candidate
		$t_candidate_monitor_table = config_get( 'cosmos_candidate_monitor_table' );
		if ( ON == email_notify_flag( $p_notify_type, 'monitor' ) ) {
			$query = "SELECT DISTINCT user_id
					  FROM $t_candidate_monitor_table
					  WHERE candidate_id=$c_candidate_id";
			$result = db_query( $query );

			$count = db_num_rows( $result );
			for ( $i=0 ; $i < $count ; $i++ ) {
				$t_user_id = db_result( $result, $i );
				$t_recipients[$t_user_id] = true;
				log_event( LOG_EMAIL_RECIPIENT, "candidate=$p_candidate_id, add monitor=$t_user_id" );
			}
		}

		# add users who contributed candidatenotes
		$t_candidatenote_id = candidatenote_get_latest_id( $p_candidate_id );
		$t_candidatenote_view = candidatenote_get_field( $t_candidatenote_id, 'view_state' );
		$t_candidatenote_date = db_unixtimestamp( candidatenote_get_field( $t_candidatenote_id, 'last_modified' ) );
		$t_candidate_date = candidate_get_field( $p_candidate_id, 'last_updated' );

		$t_candidatenote_table = config_get( 'cosmos_candidatenote_table' );
		if ( ON == email_notify_flag( $p_notify_type, 'candidatenotes' ) ) {
			$query = "SELECT DISTINCT reporter_id
					  FROM $t_candidatenote_table
					  WHERE candidate_id = $c_candidate_id";
			$result = db_query( $query );

			$count = db_num_rows( $result );
			for( $i=0 ; $i < $count ; $i++ ) {
				$t_user_id = db_result( $result, $i );
				$t_recipients[$t_user_id] = true;
				log_event( LOG_EMAIL_RECIPIENT, "candidate=$p_candidate_id, add note author=$t_user_id" );
			}
		}

		# add project users who meet the thresholds
		$t_candidate_is_private = candidate_get_field( $p_candidate_id, 'view_state' ) == VS_PRIVATE;
		$t_threshold_min = email_notify_flag( $p_notify_type, 'threshold_min' );
		$t_threshold_max = email_notify_flag( $p_notify_type, 'threshold_max' );
		$t_threshold_users = project_get_all_user_rows( $t_project_id, $t_threshold_min );
		foreach( $t_threshold_users as $t_user ) {
			if ( $t_user['access_level'] <= $t_threshold_max ) {
				if ( !$t_candidate_is_private || access_compare_level( $t_user['access_level'], config_get( 'private_candidate_threshold' ) ) ) {
					$t_recipients[$t_user['id']] = true;
					log_event( LOG_EMAIL_RECIPIENT, "candidate=$p_candidate_id, add project user=" . $t_user['id'] );
				}
			}
		}

		# set up to eliminate unwanted users
		#  get list of status values that are not covered specifically in the prefs
		#  These are handled by email_on_status generically
		#  @@@ thraxisp note that email_on_assigned was co-opted to handle change in handler
		$t_status_change = get_enum_to_array( config_get( 'status_enum_string' ) );
		unset( $t_status_change[NEW_] );
		unset( $t_status_change[FEEDBACK] );
		unset( $t_status_change[RESOLVED] );
		unset( $t_status_change[CLOSED] );

		if ( 'owner' == $p_notify_type ) {
			$t_pref_field = 'email_on_assigned';
		} else if ( in_array( $p_notify_type, $t_status_change ) ) {
			$t_pref_field = 'email_on_status';
		} else {
			$t_pref_field = 'email_on_' . $p_notify_type;
		}
		$t_user_pref_table = config_get( 'cosmos_user_pref_table' );
		if ( !db_field_exists( $t_pref_field, $t_user_pref_table ) ) {
			$t_pref_field = false;
		}

		# @@@ we could optimize by modifiying user_cache() to take an array
		#  of user ids so we could pull them all in.  We'll see if it's necessary
		$t_final_recipients = array();
		# Check whether users should receive the emails
		# and put email address to $t_recipients[user_id]
		foreach ( $t_recipients as $t_id => $t_ignore ) {
			# Possibly eliminate the current user
			if ( ( auth_get_current_user_id() == $t_id ) &&
				 ( OFF == config_get( 'email_receive_own' ) ) ) {
				log_event( LOG_EMAIL_RECIPIENT, "candidate=$p_candidate_id, drop $t_id (own)" );
				continue;
			}

			# Eliminate users who don't exist anymore or who are disabled
			if ( !user_exists( $t_id ) ||
				 !user_is_enabled( $t_id ) ) {
				log_event( LOG_EMAIL_RECIPIENT, "candidate=$p_candidate_id, drop $t_id (disabled)" );
				continue;
			}

			# Exclude users who have this notification type turned off
			if ( $t_pref_field ) {
				$t_notify = user_pref_get_pref( $t_id, $t_pref_field );
				if ( OFF == $t_notify ) {
					log_event( LOG_EMAIL_RECIPIENT, "candidate=$p_candidate_id, drop $t_id (pref $t_pref_field off)" );
					continue;
				} else {
					# Users can define the severity of an issue before they are emailed for
					# each type of notification
					$t_min_sev_pref_field = $t_pref_field . '_min_severity';
					$t_min_sev_notify     = user_pref_get_pref( $t_id, $t_min_sev_pref_field );
					$t_candidate_severity       = candidate_get_field( $p_candidate_id, 'severity' );

					if ( $t_candidate_severity < $t_min_sev_notify ) {
						log_event( LOG_EMAIL_RECIPIENT, "candidate=$p_candidate_id, drop $t_id (pref threshold)" );
						continue;
					}
				}
			}

			# check that user can see candidatenotes if the last update included a candidatenote
			if ( $t_candidate_date == $t_candidatenote_date ) {
				if ( !access_has_candidatenote_level( VIEWER, $t_candidatenote_id, $t_id ) ) {
						log_event( LOG_EMAIL_RECIPIENT, "candidate=$p_candidate_id, drop $t_id (access level)" );
					continue;
				}
			}

			# Finally, let's get their emails, if they've set one
			$t_email = user_get_email( $t_id );
			if ( is_blank( $t_email ) ) {
				log_event( LOG_EMAIL_RECIPIENT, "candidate=$p_candidate_id, drop $t_id (no email)" );
			} else {
				# @@@ we could check the emails for validity again but I think
				#   it would be too slow
				$t_final_recipients[$t_id] = $t_email;
			}
		}

		return $t_final_recipients;
	}

	# --------------------
	# Send password to user
	function email_signup( $p_user_id, $p_password, $p_confirm_hash ) {

		if ( ( OFF == config_get( 'send_reset_password' ) ) || ( OFF == config_get( 'enable_email_notification' ) ) ) {
					return;
		}

#		@@@ thraxisp - removed to address #6084 - user won't have any settings yet,
#       use same language as display for the email
#       lang_push( user_pref_get_language( $p_user_id ) );

		# retrieve the username and email
		$t_username = user_get_field( $p_user_id, 'username' );
		$t_email = user_get_email( $p_user_id );

		# Build Welcome Message
		$t_subject = '[' . config_get( 'window_title' ) . '] ' . lang_get( 'new_account_subject' );

		$t_message = lang_get( 'new_account_greeting' ) . $t_username .
						lang_get( 'new_account_greeting2' ) . " \n\n" .
						string_get_confirm_hash_url( $p_user_id, $p_confirm_hash ) . " \n\n" .
						lang_get( 'new_account_message' ) .
						lang_get( 'new_account_do_not_reply' );

		# Send signup email regardless of mail notification pref
		# or else users won't be able to sign up
		if( !is_blank( $t_email ) ) {
			email_store( $t_email, $t_subject, $t_message );
			log_event( LOG_EMAIL, "signup=$t_email, hash=$p_confirm_hash, id=$p_user_id" );

			if ( OFF == config_get( 'email_send_using_cronjob' ) ) {
				email_send_all();
			}
		}

#		lang_pop(); # see above
	}

	# --------------------
	# Send confirm_hash url to user forgets the password
	function email_send_confirm_hash_url( $p_user_id, $p_confirm_hash ) {
		if ( ( OFF == config_get( 'send_reset_password' ) ) || ( OFF == config_get( 'enable_email_notification' ) ) ) {
			return;
		}

		lang_push( user_pref_get_language( $p_user_id ) );

		# retrieve the username and email
		$t_username = user_get_field( $p_user_id, 'username' );
		$t_email = user_get_email( $p_user_id );

		$t_subject = '[' . config_get( 'window_title' ) . '] ' . lang_get( 'lost_password_subject' );

		$t_message = lang_get( 'reset_request_msg' ) . " \n\n" .
						string_get_confirm_hash_url( $p_user_id, $p_confirm_hash ) . " \n\n" .
						lang_get( 'new_account_username' ) . $t_username . " \n" .
						lang_get( 'new_account_IP' ) . $_SERVER["REMOTE_ADDR"] . " \n\n" .
						lang_get( 'new_account_do_not_reply' );

		# Send password reset regardless of mail notification prefs
		# or else users won't be able to receive their reset pws
		if( !is_blank( $t_email ) ) {
			email_store( $t_email, $t_subject, $t_message );
			log_event( LOG_EMAIL, "password_reset=$t_email" );

			if ( OFF == config_get( 'email_send_using_cronjob' ) ) {
				email_send_all();
			}
		}

		lang_pop();
	}

	# --------------------
	# notify the selected group a new user has signup
	function email_notify_new_account( $p_username, $p_email ) {
		global $g_path;

		$t_threshold_min = config_get( 'notify_new_user_created_threshold_min' );
		$t_threshold_users = project_get_all_user_rows( ALL_PROJECTS, $t_threshold_min );

		foreach( $t_threshold_users as $t_user ) {
			lang_push( user_pref_get_language( $t_user['id'] ) );

			$t_recipient_email = user_get_email( $t_user['id'] );
			$t_subject = '[' . config_get( 'window_title' ) . '] ' . lang_get( 'new_account_subject' );

			$t_message = lang_get( 'new_account_signup_msg' ) . " \n\n" .
						lang_get( 'new_account_username' ) . $p_username . " \n" .
						lang_get( 'new_account_email' ) . $p_email . " \n" .
						lang_get( 'new_account_IP' ) . $_SERVER["REMOTE_ADDR"] . " \n" .
						$g_path . "\n\n" .
						lang_get( 'new_account_do_not_reply' );

			if( !is_blank( $t_recipient_email ) ) {
				email_store( $t_recipient_email, $t_subject, $t_message );
				log_event( LOG_EMAIL, "new_account_notify=$t_recipient_email" );

				if ( OFF == config_get( 'email_send_using_cronjob' ) ) {
					email_send_all();
				}
			}

			lang_pop();
		}
	}

	# --------------------
	# send a generic email
	# $p_notify_type: use check who she get notified of such event.
	# $p_message_id: message id to be translated and included at the top of the email message.
	# Return false if it were problems sending email
	function email_generic( $p_candidate_id, $p_notify_type, $p_message_id = null, $p_header_optional_params = null ) {
		$t_ok = true;

		if ( ON === config_get( 'enable_email_notification' ) ) {
			ignore_user_abort( true );

			# @@@ yarick123: email_collect_recipients(...) will be completely rewritten to provide additional
			#     information such as language, user access,..
			# @@@ yarick123:sort recipients list by language to reduce switches between different languages
			$t_recipients = email_collect_recipients( $p_candidate_id, $p_notify_type );

			$t_project_id = candidate_get_field( $p_candidate_id, 'project_id' );
			if ( is_array( $t_recipients ) ) {
				log_event( LOG_EMAIL, sprintf("candidate=%d, type=%s, msg=%s, recipients=(%s)", $p_candidate_id, $p_notify_type, $p_message_id, implode( '. ', $t_recipients ) ) );

				# send email to every recipient
				foreach ( $t_recipients as $t_user_id => $t_user_email ) {
					# load (push) user language here as build_visible_candidate_data assumes current language
					lang_push( user_pref_get_language( $t_user_id, $t_project_id ) );

					$t_visible_candidate_data = email_build_visible_candidate_data( $t_user_id, $p_candidate_id, $p_message_id );
					$t_ok = email_candidate_info_to_one_user( $t_visible_candidate_data, $p_message_id, $t_project_id, $t_user_id, $p_header_optional_params ) && $t_ok;
					
					lang_pop();
				}
			}

			# Only trigger the draining of the email queue if cronjob is disabled and email notifications are enabled.
			if ( OFF == config_get( 'email_send_using_cronjob' ) ) {
				email_send_all();
			}
		}

		return $t_ok;
	}

	# --------------------
	# send notices when a relationship is ADDED
	# MASC RELATIONSHIP
	function email_relationship_added( $p_candidate_id, $p_related_candidate_id, $p_rel_type ) {
		$t_opt = array();
		$t_opt[] = candidate_format_id( $p_related_candidate_id );
		global $g_relationships;
		if ( !isset( $g_relationships[ $p_rel_type ] ) ) {
			trigger_error( ERROR_RELATIONSHIP_NOT_FOUND, ERROR );
		}
		email_generic( $p_candidate_id, 'relation', $g_relationships[ $p_rel_type ][ '#notify_added' ], $t_opt );
	}

	# --------------------
	# send notices when a relationship is DELETED
	# MASC RELATIONSHIP
	function email_relationship_deleted( $p_candidate_id, $p_related_candidate_id, $p_rel_type ) {
		$t_opt = array();
		$t_opt[] = candidate_format_id( $p_related_candidate_id );
		global $g_relationships;
		if ( !isset( $g_relationships[ $p_rel_type ] ) ) {
			trigger_error( ERROR_RELATIONSHIP_NOT_FOUND, ERROR );
		}
		email_generic( $p_candidate_id, 'relation', $g_relationships[ $p_rel_type ][ '#notify_deleted' ], $t_opt );
	}

	# --------------------
	# send notices to all the handlers of the parent candidates when a child candidate is RESOLVED
	# MASC RELATIONSHIP
	function email_relationship_child_resolved( $p_candidate_id ) {
		email_relationship_child_resolved_closed( $p_candidate_id, 'email_notification_title_for_action_relationship_child_resolved' );
	}

	# --------------------
	# send notices to all the handlers of the parent candidates when a child candidate is CLOSED
	# MASC RELATIONSHIP
	function email_relationship_child_closed( $p_candidate_id ) {
		email_relationship_child_resolved_closed( $p_candidate_id, 'email_notification_title_for_action_relationship_child_closed' );
	}

	# --------------------
	# send notices to all the handlers of the parent candidates still open when a child candidate is resolved/closed
	# MASC RELATIONSHIP
	function email_relationship_child_resolved_closed( $p_candidate_id, $p_message_id ) {
		# retrieve all the relationships in which the candidate is the destination candidate
		$t_relationship = relationship_get_all_dest( $p_candidate_id );
		$t_relationship_count = count( $t_relationship );
		if ( $t_relationship_count == 0 ) {
			# no parent candidate found
			return;
		}

		for ( $i = 0 ; $i < $t_relationship_count ; $i++ ) {
			if ( $t_relationship[$i]->type == BUG_DEPENDANT ) {
				$t_src_candidate_id = $t_relationship[$i]->src_candidate_id;
				$t_status = candidate_get_field( $t_src_candidate_id, 'status' );
				if ( $t_status < config_get( 'candidate_resolved_status_threshold' ) ) {
					# sent the notification just for parent candidates not resolved/closed
					$t_opt = array();
					$t_opt[] = candidate_format_id( $p_candidate_id );
					email_generic( $t_src_candidate_id, 'handler', $p_message_id, $t_opt );
				}
			}
		}
	}

	# --------------------
	# send notices when a candidate is sponsored
	function email_sponsorship_added( $p_candidate_id ) {
		email_generic( $p_candidate_id, 'sponsor', 'email_notification_title_for_action_sponsorship_added' );
	}

	# --------------------
	# send notices when a sponsorship is modified
	function email_sponsorship_updated( $p_candidate_id ) {
		email_generic( $p_candidate_id, 'sponsor', 'email_notification_title_for_action_sponsorship_updated' );
	}

	# --------------------
	# send notices when a sponsorship is deleted
	function email_sponsorship_deleted( $p_candidate_id ) {
		email_generic( $p_candidate_id, 'sponsor', 'email_notification_title_for_action_sponsorship_deleted' );
	}

	# --------------------
	# send notices when a new candidate is added
	function email_new_candidate( $p_candidate_id ) {
		email_generic( $p_candidate_id, 'new', 'email_notification_title_for_action_candidate_submitted' );
	}
	# --------------------
	# send notices when a new candidatenote
	function email_candidatenote_add( $p_candidate_id ) {
		email_generic( $p_candidate_id, 'candidatenote', 'email_notification_title_for_action_candidatenote_submitted' );
	}
	# --------------------
	# send notices when a candidate is RESOLVED
	function email_resolved( $p_candidate_id ) {
		email_generic( $p_candidate_id, 'resolved', 'email_notification_title_for_status_candidate_resolved' );
	}
	# --------------------
	# send notices when a candidate is CLOSED
	function email_close( $p_candidate_id ) {
		email_generic( $p_candidate_id, 'closed', 'email_notification_title_for_status_candidate_closed' );
	}
	# --------------------
	# send notices when a candidate is REOPENED
	function email_reopen( $p_candidate_id ) {
		email_generic( $p_candidate_id, 'reopened', 'email_notification_title_for_action_candidate_reopened' );
	}
	# --------------------
	# send notices when a candidate is ASSIGNED
	function email_assign( $p_candidate_id ) {
		email_generic( $p_candidate_id, 'owner', 'email_notification_title_for_action_candidate_assigned' );
	}
	# --------------------
	# send notices when a candidate is DELETED
	function email_candidate_deleted( $p_candidate_id ) {
		email_generic( $p_candidate_id, 'deleted', 'email_notification_title_for_action_candidate_deleted' );
	}
	# --------------------
	function email_store( $p_recipient, $p_subject, $p_message, $p_headers = null ) {
		$t_recipient = trim( $p_recipient );
		$t_subject   = string_email( trim( $p_subject ) );
		$t_message   = string_email_links( trim( $p_message ) );

		# short-circuit if no recipient is defined, or email disabled
		# note that this may cause signup messages not to be sent

		if ( is_blank( $p_recipient ) || ( OFF == config_get( 'enable_email_notification' ) ) ) {
			return;
		}

		$t_email_data = new EmailData;

		$t_email_data->email = $t_recipient;
		$t_email_data->subject = $t_subject;
		$t_email_data->body = $t_message;
		$t_email_data->metadata = array();
		$t_email_data->metadata['headers'] = $p_headers === null ? array() : $p_headers;
		$t_email_data->metadata['priority'] = config_get( 'mail_priority' );               # Urgent = 1, Not Urgent = 5, Disable = 0
		$t_email_data->metadata['charset'] =  lang_get( 'charset', lang_get_current() );

        $t_hostname = '';
        $t_server = isset( $_SERVER ) ? $_SERVER : $HTTP_SERVER_VARS;
        if ( isset( $t_server['SERVER_NAME'] ) ) {
            $t_hostname = $t_server['SERVER_NAME'];
        } else {
            $t_address = explode( '@', config_get( 'from_email' ) );
            if ( isset( $t_address[1] ) ) {
                $t_hostname = $t_address[1];
            }
        }
        $t_email_data->metadata['hostname'] = $t_hostname;
		$t_email_id = email_queue_add( $t_email_data );
		
		return $t_email_id; 
	}

	# --------------------
	# This function sends all the emails that are stored in the queue.  If a failure occurs, then the
	# function exists.  This function will be called after storing emails in case of synchronous
	# emails, or will be called from a cronjob in case of asynchronous emails.
	# @@@ In case of synchronous email sending, we may get a race condition where two requests send the same email.
	function email_send_all() {
		$t_ids = email_queue_get_ids();

		$t_emails_recipients_failed = array();
		$t_start = microtime_float();
		foreach ( $t_ids as $t_id ) {
			$t_email_data = email_queue_get( $t_id );

			# check if email was not found.  This can happen if another request picks up the email first and sends it.
			if ( $t_email_data === false ) {
				continue;
			}

			# if unable to place the email in the email server queue, then the connection to the server is down,
			# and hence no point to continue trying with the rest of the emails.
			if ( !email_send( $t_email_data ) ) {
				if ( microtime_float() - $t_start > 5)
					break;
				else 
					continue;
			}
		}
	}

	# --------------------
	# This function sends an email message based on the supplied email data.
	function email_send( $p_email_data ) {
		global $g_phpMailer_smtp;

		$t_email_data = $p_email_data;

		$t_recipient = trim( $t_email_data->email );
		$t_subject   = string_email( trim( $t_email_data->subject ) );
		$t_message   = string_email_links( trim( $t_email_data->body ) );

		$t_debug_email = config_get( 'debug_email' );

		# Visit http://phpmailer.sourceforge.net
		# if you have problems with phpMailer

		$mail = new PHPMailer;
		$mail->PluginDir = PHPMAILER_PATH;
		
		if ( isset( $t_email_data->metadata['hostname'] ) ) {
			$mail->Hostname = $t_email_data->metadata['hostname'];
		}

		# @@@ should this be the current language (for the recipient) or the default one (for the user running the command) (thraxisp)
		$t_lang = config_get( 'default_language' );
		if ( 'auto' == $t_lang ) {
			$t_lang = config_get( 'fallback_language');
		}
		$mail->SetLanguage( lang_get( 'phpmailer_language', $t_lang ), PHPMAILER_PATH . 'language' . DIRECTORY_SEPARATOR );

		# Select the method to send mail
		switch ( config_get( 'phpMailer_method' ) ) {
			case 0: $mail->IsMail();
					break;

			case 1: $mail->IsSendmail();
					break;

			case 2: $mail->IsSMTP();
					{
						# SMTP collection is always kept alive
						#
						$mail->SMTPKeepAlive = true;

						# @@@ yarick123: It is said in phpMailer comments, that phpMailer::smtp has private access.
						# but there is no common method to reset PHPMailer object, so
						# I see the smallest evel - to initialize only one 'private'
						# field phpMailer::smtp in order to reuse smtp connection.

						if( is_null( $g_phpMailer_smtp ) )  {
							register_shutdown_function( 'email_smtp_close' );
						} else {
							$mail->smtp = $g_phpMailer_smtp;
						}
					}
					break;
		}

		$mail->IsHTML( false );              # set email format to plain text
		$mail->WordWrap = 80;              # set word wrap to 50 characters
		$mail->Priority = $t_email_data->metadata['priority'];  # Urgent = 1, Not Urgent = 5, Disable = 0
		$mail->CharSet = $t_email_data->metadata['charset'];
		$mail->Host     = config_get( 'smtp_host' );
		$mail->From     = config_get( 'from_email' );
		$mail->Sender   = escapeshellcmd( config_get( 'return_path_email' ) );
		$mail->FromName = config_get( 'from_name');


		if ( !is_blank( config_get( 'smtp_username' ) ) ) {     # Use SMTP Authentication
			$mail->SMTPAuth = true;
			$mail->Username = config_get( 'smtp_username' );
			$mail->Password = config_get( 'smtp_password' );
		}

		if ( OFF !== $t_debug_email ) {
			$t_message = 'To: '. $t_recipient . "\n\n" . $t_message;
			$mail->AddAddress( $t_debug_email, '' );
		} else {
			$mail->AddAddress( $t_recipient, '' );
		}

		$mail->Subject = $t_subject;
		$mail->Body    = make_lf_crlf( "\n" . $t_message );

		if ( isset( $t_email_data->metadata['headers'] ) && is_array( $t_email_data->metadata['headers'] ) ) {
			foreach ( $t_email_data->metadata['headers'] as $t_key => $t_value ) {
				$mail->AddCustomHeader( "$t_key: $t_value" );
			}
		}

		if ( !$mail->Send() ) {
			$t_success = false;
		} else {
			$t_success = true;

			if ( $t_email_data->email_id > 0 ) {
				email_queue_delete( $t_email_data->email_id );
			}
		}

		if ( !is_null( $mail->smtp ) )  {
			# @@@ yarick123: It is said in phpMailer comments, that phpMailer::smtp has private access.
			# but there is no common method to reset PHPMailer object, so
			# I see the smallest evel - to initialize only one 'private'
			# field phpMailer::smtp in order to reuse smtp connection.
			$g_phpMailer_smtp = $mail->smtp;
		}

		return $t_success;
	}

	# --------------------
	# closes opened kept alive SMTP connection (if it was opened)
	function email_smtp_close()  {
		global $g_phpMailer_smtp;

		if ( !is_null( $g_phpMailer_smtp ) )  {
			if ( $g_phpMailer_smtp->Connected() )  {
				$g_phpMailer_smtp->Quit();
				$g_phpMailer_smtp->Close();
			}
			$g_phpMailer_smtp = null;
		}
	}
	# --------------------
	# formats the subject correctly
	# we include the project name, candidate id, and summary.
	function email_build_subject( $p_candidate_id ) {
		# grab the project name
		$p_project_name = project_get_field( candidate_get_field( $p_candidate_id, 'project_id' ), 'name' );

		# grab the subject (summary)
		$p_subject = candidate_get_field( $p_candidate_id, 'summary' );

		# padd the candidate id with zeros
		$p_candidate_id = candidate_format_id( $p_candidate_id );

		return '['.$p_project_name.' '.$p_candidate_id.']: '.$p_subject;
	}
	# --------------------
	# clean up LF to CRLF
	function make_lf_crlf( $p_string ) {
		$t_string = str_replace( "\n", "\r\n", $p_string );
		return str_replace( "\r\r\n", "\r\n", $t_string );
	}
	# --------------------
	# Check limit_email_domain option and append the domain name if it is set
	function email_append_domain( $p_email ) {
		$t_limit_email_domain = config_get( 'limit_email_domain' );
		if ( $t_limit_email_domain && !is_blank( $p_email ) ) {
			$p_email = "$p_email@$t_limit_email_domain";
		}

		return $p_email;
	}
	# --------------------
	# Send a candidate reminder to each of the given user, or to each user if the first
	#  parameter is an array
	# return an array of usernames to which the reminder was successfully sent
	#
	# @@@ I'm not sure this shouldn't return an array of user ids... more work for
	#  the caller but cleaner from an API point of view.
	function email_candidate_reminder( $p_recipients, $p_candidate_id, $p_message ) {

		if ( !is_array( $p_recipients ) ) {
			$p_recipients = array( $p_recipients );
		}

		$t_project_id = candidate_get_field( $p_candidate_id, 'project_id' );
		$t_sender_id = auth_get_current_user_id();
		$t_sender = user_get_name( $t_sender_id );

		$t_subject = email_build_subject( $p_candidate_id );
		$t_date = date( config_get( 'normal_date_format' ) );
		
		$result = array();
		foreach ( $p_recipients as $t_recipient ) {
			lang_push( user_pref_get_language( $t_recipient, $t_project_id ) );

			$t_email = user_get_email( $t_recipient );
			$result[] = user_get_name( $t_recipient );

			if ( access_has_project_level( config_get( 'show_user_email_threshold' ), $t_project_id, $t_recipient ) ) {
				$t_sender_email = ' <' . current_user_get_field( 'email' ) . '>' ;
			} else {
				$t_sender_email = '';
			}
			$t_header = "\n" . lang_get( 'on' ) . " $t_date, $t_sender $t_sender_email " .
						lang_get( 'sent_you_this_reminder_about' ) . ": \n\n";
			$t_contents = $t_header .
							string_get_candidate_view_url_with_fqdn( $p_candidate_id, $t_recipient ) .
							" \n\n$p_message";

			if( ON == config_get( 'enable_email_notification' ) ) {
				email_store( $t_email, $t_subject, $t_contents );
			}

			lang_pop();
		}

		if ( OFF == config_get( 'email_send_using_cronjob' ) ) {
			email_send_all();
		}

		return $result;
	}

	# --------------------
	# Send candidate info to given user
	# return true on success
	function email_candidate_info_to_one_user( $p_visible_candidate_data, $p_message_id, $p_project_id, $p_user_id, $p_header_optional_params = null ) {

		$t_user_email = user_get_email( $p_user_id );

		# check whether email should be sent
		# @@@ can be email field empty? if yes - then it should be handled here
		if ( ON !== config_get( 'enable_email_notification' ) || is_blank( $t_user_email ) ) {
			return true;
		}

		# build subject
		$t_subject = '['.$p_visible_candidate_data['email_project'].' '
						.candidate_format_id( $p_visible_candidate_data['email_candidate'] )
					.']: '.$p_visible_candidate_data['email_summary'];

		# build message

		$t_message = lang_get_defaulted( $p_message_id, null );
	
		if ( is_array( $p_header_optional_params ) ) {
			$t_message = vsprintf( $t_message, $p_header_optional_params );
		}

		if ( ( $t_message !== null ) && ( !is_blank( $t_message ) ) ) {
			$t_message .= " \n";
		}

		$t_message .= email_format_candidate_message(  $p_visible_candidate_data );

		# build headers
		$t_candidate_id = $p_visible_candidate_data['email_candidate'];
		$t_message_md5 = md5( $t_candidate_id . $p_visible_candidate_data['email_date_submitted'] );
		$t_mail_headers = array( 'keywords' => $p_visible_candidate_data['set_category'] );
		if ( $p_message_id == 'email_notification_title_for_action_candidate_submitted' ) {
			$t_mail_headers['Message-ID'] = "<{$t_message_md5}>";
		} else {
			$t_mail_headers['In-Reply-To'] = "<{$t_message_md5}>";
		}

		# send mail
		# PRINT '<br />email_candidate_info::Sending email to :'.$t_user_email;
		$t_ok = email_store( $t_user_email, $t_subject, $t_message, $t_mail_headers );

		return $t_ok;
	}

	# --------------------
	# Build the candidate info part of the message
	function email_format_candidate_message( $p_visible_candidate_data ) {

		$t_normal_date_format = config_get( 'normal_date_format' );
		$t_complete_date_format = config_get( 'complete_date_format' );

		$t_email_separator1 = config_get( 'email_separator1' );
		$t_email_separator2 = config_get( 'email_separator2' );
		$t_email_padding_length = config_get( 'email_padding_length' );

		$t_status = $p_visible_candidate_data['email_status'];

		$p_visible_candidate_data['email_date_submitted'] = date( $t_complete_date_format, $p_visible_candidate_data['email_date_submitted'] );
		$p_visible_candidate_data['email_last_modified']   = date( $t_complete_date_format, $p_visible_candidate_data['email_last_modified'] );

		$p_visible_candidate_data['email_status'] = get_enum_element( 'status', $t_status );
		$p_visible_candidate_data['email_severity'] = get_enum_element( 'severity', $p_visible_candidate_data['email_severity'] );
		$p_visible_candidate_data['email_priority'] = get_enum_element( 'priority', $p_visible_candidate_data['email_priority'] );
		$p_visible_candidate_data['email_reproducibility'] = get_enum_element( 'reproducibility', $p_visible_candidate_data['email_reproducibility'] );

		$t_message = $t_email_separator1 . " \n";

		if ( isset( $p_visible_candidate_data['email_candidate_view_url'] ) ) {
			$t_message .= $p_visible_candidate_data['email_candidate_view_url'] . " \n";
			$t_message .= $t_email_separator1 . " \n";
		}
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_summary' );
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_status' );
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_reporter','email_reporter_access_level');
		if (''!= $p_visible_candidate_data['email_handler_access_level'] ) {
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_handler','email_handler_access_level');
		}
		$t_message .= $t_email_separator1 . " \n";
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_project' );
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_candidate' );
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_category' );
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_reproducibility' );
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_severity' );
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_priority' );
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_status' );
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_target_version' );


		# custom fields formatting
		foreach( $p_visible_candidate_data['custom_fields'] as $t_custom_field_name => $t_custom_field_data ) {
			$t_message .= str_pad( lang_get_defaulted( $t_custom_field_name, null ) . ': ', $t_email_padding_length, ' ', STR_PAD_RIGHT );
			$t_message .= string_custom_field_value_for_email ( $t_custom_field_data['value'], $t_custom_field_data['type'] );
			$t_message .= " \n";
		} # end foreach custom field

		if ( config_get( 'candidate_resolved_status_threshold' ) <= $t_status ) {
			$p_visible_candidate_data['email_resolution'] = get_enum_element( 'resolution', $p_visible_candidate_data['email_resolution'] );
			$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_resolution' );
			$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_duplicate' );
			$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_fixed_in_version' );
		}
		$t_message .= $t_email_separator1 . " \n";

		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_date_submitted' );
		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_last_modified' );
		$t_message .= $t_email_separator1 . " \n";

		$t_message .= email_format_attribute( $p_visible_candidate_data, 'email_summary' );

		$t_message .= lang_get( 'email_description' ) . ": \n".wordwrap( $p_visible_candidate_data['email_description'] )."\n";

		# MASC RELATIONSHIP
		if ( ON == config_get( 'enable_relationship' ) ) {
			if (isset( $p_visible_candidate_data['relations'] )) {
				$t_message .= $p_visible_candidate_data['relations'];
			}
		}
		# MASC RELATIONSHIP

		# Sponsorship
		if ( isset( $p_visible_candidate_data['sponsorship_total'] ) && ( $p_visible_candidate_data['sponsorship_total'] > 0 ) ) {
			$t_message .= $t_email_separator1 . " \n";
			$t_message .= sprintf( lang_get( 'total_sponsorship_amount' ), sponsorship_format_amount( $p_visible_candidate_data['sponsorship_total'] ) ) . "\n" . "\n";

			if ( isset( $p_visible_candidate_data['sponsorships'] ) ) {
				foreach ( $p_visible_candidate_data['sponsorships'] as $t_sponsorship ) {
					$t_date_added = date( config_get( 'normal_date_format' ), $t_sponsorship->date_submitted );

					$t_message .= $t_date_added . ': ';
					$t_message .= user_get_name( $t_sponsorship->user_id );
					$t_message .= ' (' . sponsorship_format_amount( $t_sponsorship->amount ) . ')' . " \n";
				}
			}
		}

		$t_message .= $t_email_separator1 . " \n\n";

		# format candidatenotes
		foreach ( $p_visible_candidate_data['candidatenotes'] as $t_candidatenote ) {
			$t_last_modified = date( $t_normal_date_format, $t_candidatenote->last_modified );

			$t_formatted_candidatenote_id = candidatenote_format_id( $t_candidatenote->id );
			$t_candidatenote_link = string_process_candidatenote_link ( config_get( 'candidatenote_link_tag' ) . $t_candidatenote->id, false, false, true );

			$t_time_tracking = '';

			# the candidatenote objects array is retrieved from candidatenote_get_all_visible_candidatenotes which already checks for
			# time_tracking_view_threshold.  If user does not have view permission the value is set to 0.
			if ( $t_candidatenote->time_tracking > 0 ) {
				$t_time_tracking_minutes = candidatenote_get_field ( $t_candidatenote->id, 'time_tracking' );
				if ( $t_time_tracking_minutes > 0 ) {
					$t_time_tracking = ' ' . lang_get( 'time_tracking' ) . ' ' . db_minutes_to_hhmm( $t_time_tracking_minutes ) . "\n";
				}
			}

			if ( user_exists( $t_candidatenote->reporter_id ) ) {
				$t_access_level = access_get_project_level( null,  $t_candidatenote->reporter_id );
				$t_access_level_string = ' (' . get_enum_element( 'access_levels', $t_access_level ) . ') - '; 
			} else {
				$t_access_level_string = '';
			}

			$t_string = ' (' . $t_formatted_candidatenote_id . ') ' . 
				user_get_name( $t_candidatenote->reporter_id ) .
				$t_access_level_string .
				$t_last_modified . "\n" .
				$t_time_tracking . ' ' . $t_candidatenote_link;

			$t_message .= $t_email_separator2 . " \n";
			$t_message .= $t_string . " \n";
			$t_message .= $t_email_separator2 . " \n";
			$t_message .= wordwrap( $t_candidatenote->note ) . " \n\n";
		}

		# format history
		if ( array_key_exists( 'history', $p_visible_candidate_data ) ) {
			$t_message .=	lang_get( 'candidate_history' ) . " \n";
			$t_message .=	str_pad( lang_get( 'date_modified' ), 17 ) .
							str_pad( lang_get( 'username' ), 15 ) .
							str_pad( lang_get( 'field' ), 25 ) .
							str_pad( lang_get( 'change' ), 20 ). " \n";

			$t_message .= $t_email_separator1 . " \n";

			foreach ( $p_visible_candidate_data['history'] as $t_raw_history_item ) {
				$t_localized_item = history_localize_item(	$t_raw_history_item['field'],
															$t_raw_history_item['type'],
															$t_raw_history_item['old_value'],
															$t_raw_history_item['new_value'] );

				$t_message .=	str_pad( date( $t_normal_date_format, $t_raw_history_item['date'] ), 17 ) .
								str_pad( $t_raw_history_item['username'], 15 ) .
								str_pad( $t_localized_item['note'], 25 ) .
								str_pad( $t_localized_item['change'], 20 ) . "\n";
			}
			$t_message .= $t_email_separator1 . " \n\n";
		}

		return $t_message;
	}

	# --------------------
	# if $p_visible_candidate_data contains specified attribute the function
	# returns concatenated translated attribute name and original
	# attribute value. Else return empty string.
	# Updated by Chirag A to show extra value in data like ()
	function email_format_attribute( $p_visible_candidate_data, $attribute_id, $add_p_visible_candidate_data="") {

		if ( array_key_exists( $attribute_id, $p_visible_candidate_data ) ) {
		if($add_p_visible_candidate_data==""){
			return str_pad( lang_get( $attribute_id ) . ': ', config_get( 'email_padding_length' ), ' ', STR_PAD_RIGHT ).$p_visible_candidate_data[$attribute_id]."\n";
			}
			else{
				if($p_visible_candidate_data[$add_p_visible_candidate_data]<>""){
				return str_pad( lang_get( $attribute_id ) . ': ', config_get( 'email_padding_length' ), ' ', STR_PAD_RIGHT ).$p_visible_candidate_data[$attribute_id]."(".$p_visible_candidate_data[$add_p_visible_candidate_data].")\n";
				}else{
			return str_pad( lang_get( $attribute_id ) . ': ', config_get( 'email_padding_length' ), ' ', STR_PAD_RIGHT ).$p_visible_candidate_data[$attribute_id]."\n";
				
				}
			
		}
	}
		return '';
	}

	# --------------------
	# Build the candidate raw data visible for specified user to be translated and sent by email to the user
	# (Filter the candidate data according to user access level)
	# return array with candidate data. See usage in email_format_candidate_message(...)
	function email_build_visible_candidate_data( $p_user_id, $p_candidate_id, $p_message_id ) {
		$t_project_id = candidate_get_field( $p_candidate_id, 'project_id' );
		$t_user_access_level = user_get_access_level( $p_user_id, $t_project_id );
		$t_user_candidatenote_order = user_pref_get_pref ( $p_user_id, 'candidatenote_order' );
		$t_user_candidatenote_limit = user_pref_get_pref ( $p_user_id, 'email_candidatenote_limit' );

		$row = candidate_get_extended_row( $p_candidate_id );
		$t_candidate_data = array();

		$t_candidate_data['email_candidate'] = $p_candidate_id;

		if ( $p_message_id !== 'email_notification_title_for_action_candidate_deleted' ) {
			$t_candidate_data['email_candidate_view_url'] = string_get_candidate_view_url_with_fqdn( $p_candidate_id );
		}

		if ( access_compare_level( $t_user_access_level, config_get( 'view_handler_threshold' ) ) ) {
			if ( 0 != $row['handler_id'] ) {
				$t_candidate_data['email_handler'] = user_get_name( $row['handler_id'] );
			} else {
				$t_candidate_data['email_handler'] = '';
			}
		}

		$t_candidate_data['email_reporter'] = user_get_name( $row['reporter_id'] );
		$t_candidate_data['email_project']  = project_get_field( $row['project_id'], 'name' );
		$t_candidate_data['email_reporter_access_level'] = get_enum_element('access_levels', user_get_access_level($row['reporter_id'],$row['project_id'])); 
	if ( 0 != $row['handler_id'] ) {
		$t_candidate_data['email_handler_access_level'] = get_enum_element('access_levels', user_get_access_level($row['handler_id'],$row['project_id'])); 
}

		$t_candidate_data['email_category'] = $row['category'];

		$t_candidate_data['email_date_submitted'] = $row['date_submitted'];
		$t_candidate_data['email_last_modified']   = $row['last_updated'];

		$t_candidate_data['email_status'] = $row['status'];
		$t_candidate_data['email_severity'] = $row['severity'];
		$t_candidate_data['email_priority'] = $row['priority'];
		$t_candidate_data['email_reproducibility'] = $row['reproducibility'];

		$t_candidate_data['email_resolution'] = $row['resolution'];
		$t_candidate_data['email_fixed_in_version'] = $row['fixed_in_version'];

		if ( !is_blank( $row['target_version'] ) && access_compare_level( $t_user_access_level, config_get( 'roadmap_view_threshold' ) ) ) {
			$t_candidate_data['email_target_version'] = $row['target_version'];
		}

		if ( DUPLICATE == $row['resolution'] ) {
			$t_candidate_data['email_duplicate'] = $row['duplicate_id'];
		}

		$t_candidate_data['email_summary'] = $row['summary'];
		$t_candidate_data['email_description'] = $row['description'];

		$t_candidate_data['set_category'] = '[' . $t_candidate_data['email_project'] . '] ' . $row['category'];

		$t_candidate_data['custom_fields'] = custom_field_get_linked_fields( $p_candidate_id, $t_user_access_level );
		$t_candidate_data['candidatenotes'] = candidatenote_get_all_visible_candidatenotes( $p_candidate_id, $t_user_access_level, $t_user_candidatenote_order, $t_user_candidatenote_limit );

		# put history data
		if ( ( ON == config_get( 'history_default_visible' ) ) &&  access_compare_level( $t_user_access_level, config_get( 'view_history_threshold' ) ) ) {
			$t_candidate_data['history']  = history_get_raw_events_array( $p_candidate_id, $p_user_id );
		}

		# Sponsorship Information
		if ( ( config_get( 'enable_sponsorship' ) == ON ) && ( access_has_candidate_level( config_get( 'view_sponsorship_total_threshold' ), $p_candidate_id, $p_user_id ) ) ) {
			$t_sponsorship_ids = sponsorship_get_all_ids( $p_candidate_id );
			$t_candidate_data['sponsorship_total'] = sponsorship_get_amount( $t_sponsorship_ids );

			if ( access_has_candidate_level( config_get( 'view_sponsorship_details_threshold' ), $p_candidate_id, $p_user_id ) ) {
				$t_candidate_data['sponsorships'] = array();
				foreach ( $t_sponsorship_ids as $id ) {
					$t_candidate_data['sponsorships'][] = sponsorship_get( $id );
				}
			}
		}

		# MASC RELATIONSHIP
		if ( ON == config_get( 'enable_relationship' ) ) {
			$t_candidate_data['relations'] = relationship_get_summary_text( $p_candidate_id );
		}

		return $t_candidate_data;
	}
	
############################################################
# 
# Invitation Functions 
# 
# ##########################################################

	class invitation {

		var $i_date = "";      
		var $i_time  = "";    
		var $i_location  = "";
		var $i_start = "";     
		var $i_end = "";       
		var $i_body = "";     
		var $i_uid = "";     
		var $i_headers = "";     
		var $i_calevent = "";     
		var $i_attendee_string = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:MAILTO:';

		const first_date_str = '1st Interview Date';
		const first_time_str = '1st Interview Time';
		const location_str = 'Interview Room';
		const second_date_str = '2nd Interview Date';
		const second_time_str = '2nd Interview Time';
		
		public function _construct() {
			$this->i_headers = "From: COSMOS@nsn.com\n"; 
			$this->i_headers .= "MIME-Version: 1.0\n"; 
			$this->i_headers .= "Content-Type: text/calendar; method=REQUEST;/n"; 
			$this->i_headers .= ' charset="UTF-8"'; 
			$this->i_headers .= "/n"; 
			$this->i_headers .= "Content-Transfer-Encoding: 8bit";
		}
		private function buildCalEvent(){
			$message = "
			BEGIN:VCALENDAR\n
			METHOD:REQUEST\n
			PRODID:-//Microsoft Corporation//Outlook 14.0 MIMEDIR//EN\n 
			VERSION:2.0\n
			BEGIN:VEVENT\n
			DTSTAMP:$p_start\n
			DTSTART:$p_start\n
			DTEND:$p_end\n
			SUMMARY:Interview Invitation\n
			UID:$uid\n
			$attendees
			ORGANIZER:MAILTO:cosmos@nsn.com\n
			LOCATION:$p_location\n
			DESCRIPTION:$p_body\n
			SEQUENCE:0\n
			PRIORITY:5\n
			CLASS:PUBLIC\n
			STATUS:CONFIRMED\n
			TRANSP:OPAQUE\n
			BEGIN:VALARM\n
			ACTION:DISPLAY\n
			DESCRIPTION:REMINDER\n
			TRIGGER;RELATED=START:-PT00H15M00S\n
			END:VALARM\n
			END:VEVENT\n
			END:VCALENDAR\n";

		}
		public function create($type, $name, $id,  $post_data=null){
			
			$oInvitation = new invitation;

			$oInvitation->i_uid = $id;	
			$t_location = invitation::location_str;
			if ($type == 1) {
				$t_date_str = invitation::first_date_str;
				$t_time_str = invitation::first_time_str;
			} else {
				$t_date_str = invitation::second_date_str;
				$t_time_str = invitation::second_time_str;
			}
			$oInvitation->i_date      = custom_field_get_value_from($t_date_str, $id, $post_data);
			$oInvitation->i_time      = custom_field_get_value_from($t_time_str, $id, $post_data);
			$oInvitation->i_location  = custom_field_get_value_from($t_location, $id, $post_data);
			$oInvitation->i_start     = format_start($oInvitation->i_date,$oInvitation->i_time);
			$oInvitation->i_end       = format_end($oInvitation->i_start);
			$oInvitation->i_body      = lang_get('body_invitation_1') . $name . '. '; 
			$oInvitation->i_body     .= lang_get('body_invitation_link') . get_candidate_hyperlink($id);

			return $oInvitation;
		}
		public function send($p_to) {

			echo $this->i_date ;
			echo $this->i_time  ;
			echo $this->i_location  ;
			echo $this->i_start ;
			echo $this->i_end ;
			echo $this->i_body ;
		}
	}

	function email_send_invitation($p_to, $p_start, $p_end, $p_location,$p_subject, $p_body, $uid) {
		if ($p_start == 0 or $p_end == 0 or $p_to == ''){
			return;
		}

		$headers = "From: COSMOS@nsn.com\n"; 
		$headers .= "MIME-Version: 1.0\n"; 
		$headers .= "Content-Type: text/calendar; method=REQUEST;/n"; 
		$headers .= ' charset="UTF-8"'; 
		$headers .= "/n"; 
		$headers .= "Content-Transfer-Encoding: 8bit";
		$message = "
		BEGIN:VCALENDAR\n
		METHOD:REQUEST\n
		PRODID:-//Microsoft Corporation//Outlook 14.0 MIMEDIR//EN\n 
		VERSION:2.0\n
		BEGIN:VEVENT\n
		DTSTAMP:$p_start\n
		DTSTART:$p_start\n
		DTEND:$p_end\n
		SUMMARY:Interview Invitation\n
		UID:$uid\n
		ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:MAILTO:$p_to\n
		ORGANIZER:MAILTO:cosmos@nsn.com\n
		LOCATION:$p_location\n
		DESCRIPTION:$p_body\n
		SEQUENCE:0\n
		PRIORITY:5\n
		CLASS:PUBLIC\n
		STATUS:CONFIRMED\n
		TRANSP:OPAQUE\n
		BEGIN:VALARM\n
		ACTION:DISPLAY\n
		DESCRIPTION:REMINDER\n
		TRIGGER;RELATED=START:-PT00H15M00S\n
		END:VALARM\n
		END:VEVENT\n
		END:VCALENDAR\n";

		$t_subject = "Interview Invitation [" .  $p_subject . "]";
		$t_debug_email = config_get( 'debug_email' );
		if ( OFF !== $t_debug_email ) {
			mail($t_debug_email, $t_subject, $message, $headers);
		} else {
			mail($p_to, $t_subject, $message, $headers);
		}
	}
	
	function email_send_invitations($p_to, $p_start, $p_end, $p_location,$p_subject, $p_body, $uid) {
		if ($p_start == 0 or $p_end == 0 or $p_to == ''){
			return;
		}
		$t_recipients = array();
		$attendee_string = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:MAILTO:';
				
		#
		# Mass send to all Manager level users at the moment only
		#
		if ($p_to == 'MANAGERS') {
			$t_recipients = user_get_manager_emails();
		} else {
			return;
		}
		
		$attendees = '';
		foreach ($t_recipients as $t_recipient => $t_mail){
			$attendees .= $attendee_string . $t_mail . "\n";
		}
		$headers = "From: COSMOS@nsn.com\n"; 
		$headers .= "MIME-Version: 1.0\n"; 
		$headers .= "Content-Type: text/calendar; method=REQUEST;/n"; 
		$headers .= ' charset="UTF-8"'; 
		$headers .= "/n"; 
		$headers .= "Content-Transfer-Encoding: 8bit";
		$message = "
		BEGIN:VCALENDAR\n
		METHOD:REQUEST\n
		PRODID:-//Microsoft Corporation//Outlook 14.0 MIMEDIR//EN\n 
		VERSION:2.0\n
		BEGIN:VEVENT\n
		DTSTAMP:$p_start\n
		DTSTART:$p_start\n
		DTEND:$p_end\n
		SUMMARY:Interview Invitation\n
		UID:$uid\n
		$attendees
		ORGANIZER:MAILTO:cosmos@nsn.com\n
		LOCATION:$p_location\n
		DESCRIPTION:$p_body\n
		SEQUENCE:0\n
		PRIORITY:5\n
		CLASS:PUBLIC\n
		STATUS:CONFIRMED\n
		TRANSP:OPAQUE\n
		BEGIN:VALARM\n
		ACTION:DISPLAY\n
		DESCRIPTION:REMINDER\n
		TRIGGER;RELATED=START:-PT00H15M00S\n
		END:VALARM\n
		END:VEVENT\n
		END:VCALENDAR\n";
		
		$t_subject = "2nd Interview Scheduled Invitation [" .  $p_subject . "]";
		
		foreach ($t_recipients as $t_recipient => $t_mail){
			$t_debug_email = config_get( 'debug_email' );
			if ( OFF !== $t_debug_email ) {
				mail($t_debug_email, $t_subject, $message, $headers);
			} else {
				mail($t_mail, $t_subject, $message, $headers);
			}
		}
	}

	function email_send_interview_reminders($send_reminder, $name, $candidate_id=0) {

		$t_recipients = array();
		$t_recipients = user_get_interviewer_emails();
		
		foreach ($t_recipients as $t_recipient => $t_mail){

			if ($candidate_id==0){
				$subject = '[' . config_get( 'window_title' ) . '] ' . lang_get( 'unassigned_interviews' );
				$message = 'Hello,' . "\n\n" . 
				lang_get( 'unassigned_interviews_msg' )     .  " \n\n" .
				lang_get( 'unassigned_home_box')            .  " \n\n" . 
				lang_get( 'unassigned_interviews_msg_end' ) .  " \n  "; 
			} else {
				$subject = lang_get('interview_available_subject') . '[' . $name . ']' ;
				$message = 'Hello,' . "\n\n" . 
				lang_get( 'interview_available' )     .  " \n\n" .
				lang_get( 'view_candidate') . get_candidate_hyperlink( $candidate_id ) . "\n\n" .
				lang_get( 'unassigned_interviews_msg_end' ) .  " \n  "; 
			}
			if( !is_blank( $t_mail ) ) {
				if ($send_reminder == 'yes'){
					email_store( $t_mail, $subject, $message );
				}
			}
		}
	}
?>
