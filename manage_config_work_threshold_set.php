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
	# $Id: manage_config_work_threshold_set.php,v 1.10.2.1 2007-10-13 22:33:25 giallu Exp $
	# --------------------------------------------------------

	require_once( 'core.php' );

	$t_core_path = config_get( 'core_path' );
	require_once( $t_core_path.'email_api.php' );

	# helper_ensure_post();

	auth_reauthenticate();

	$t_redirect_url = 'manage_config_work_threshold_page.php';
	$t_project = helper_get_current_project();

	html_page_top1( lang_get( 'manage_threshold_config' ) );
	html_meta_redirect( $t_redirect_url );
	html_page_top2();

	$t_access = current_user_get_access_level();

	function set_capability_row( $p_threshold, $p_all_projects_only=false ) {
	    global $t_access, $t_project;

	    if ( ( $t_access >= config_get_access( $p_threshold ) )
		          && ( ( ALL_PROJECTS == $t_project ) || ! $p_all_projects_only ) ) {
	        $f_threshold = gpc_get_int_array( 'flag_thres_' . $p_threshold, array( ) );
	        $f_access = gpc_get_int( 'access_' . $p_threshold );
            # @@debug @@ echo "<br />for $p_threshold "; var_dump($f_threshold, $f_access); echo '<br />';
		    $t_access_levels = get_enum_to_array( config_get( 'access_levels_enum_string' ) );
		    ksort( $t_access_levels );
		    reset( $t_access_levels );

		    $t_lower_threshold = NOBODY;
		    $t_array_threshold = array();

		    foreach( $t_access_levels as $t_access_level => $t_level_name ) {
		        if ( in_array( $t_access_level, $f_threshold ) ) {
		            if ( NOBODY == $t_lower_threshold ) {
		                $t_lower_threshold = $t_access_level;
		            }
		            $t_array_threshold[] = $t_access_level;
		        } else {
		            if ( NOBODY <> $t_lower_threshold ) {
		                $t_lower_threshold = -1;
		            }
		        }
            # @@debug @@ var_dump($$t_access_level, $t_lower_threshold, $t_array_threshold); echo '<br />';
            }
            $t_existing_threshold = config_get( $p_threshold );
            if ( -1 == $t_lower_threshold ) {
                if ( $t_existing_threshold != $t_array_threshold ) {
                    config_set( $p_threshold, $t_array_threshold, NO_USER, $t_project, $f_access );
                }
		    } else {
                if ( $t_existing_threshold != $t_lower_threshold ) {
                    config_set( $p_threshold, $t_lower_threshold, NO_USER, $t_project, $f_access );
                }
		    }
		}
	}

	function set_capability_boolean( $p_threshold, $p_all_projects_only=false ) {
	    global $t_access, $t_project;

	    if ( ( $t_access >= config_get_access( $p_threshold ) )
		          && ( ( ALL_PROJECTS == $t_project ) || ! $p_all_projects_only ) ) {
	        $f_flag = gpc_get( 'flag_' . $p_threshold, OFF );
	        $f_access = gpc_get_int( 'access_' . $p_threshold );
	        $f_flag = ( OFF == $f_flag ) ? OFF : ON;
            # @@debug @@ echo "<br />for $p_threshold "; var_dump($f_flag, $f_access); echo '<br />';

            if ( $f_flag != config_get( $p_threshold ) ) {
                config_set( $p_threshold, $f_flag, NO_USER, $t_project, $f_access );
            }
		}
	}

	function set_capability_enum( $p_threshold, $p_all_projects_only=false ) {
	    global $t_access, $t_project;

	    if ( ( $t_access >= config_get_access( $p_threshold ) )
		          && ( ( ALL_PROJECTS == $t_project ) || ! $p_all_projects_only ) ) {
	        $f_flag = gpc_get( 'flag_' . $p_threshold );
	        $f_access = gpc_get_int( 'access_' . $p_threshold );
            # @@debug @@ echo "<br />for $p_threshold "; var_dump($f_flag, $f_access); echo '<br />';

            if ( $f_flag != config_get( $p_threshold ) ) {
                config_set( $p_threshold, $f_flag, NO_USER, $t_project, $f_access );
            }
		}
	}


	# Issues
	set_capability_row( 'report_candidate_threshold' );
    set_capability_enum( 'candidate_submit_status' );
	set_capability_row( 'update_candidate_threshold' );
	set_capability_boolean( 'allow_close_immediately' );
    set_capability_boolean( 'allow_reporter_close' );
	set_capability_row( 'monitor_candidate_threshold' );
	set_capability_row( 'handle_candidate_threshold' );
 	set_capability_row( 'update_candidate_assign_threshold' );
	set_capability_row( 'move_candidate_threshold', true );
	set_capability_row( 'delete_candidate_threshold' );
	set_capability_row( 'reopen_candidate_threshold' );
    set_capability_boolean( 'allow_reporter_reopen' );
    set_capability_enum( 'candidate_reopen_status' );
    set_capability_enum( 'candidate_reopen_resolution' );
    set_capability_enum( 'candidate_resolved_status_threshold' );
    set_capability_enum( 'candidate_readonly_status_threshold' );
	set_capability_row( 'private_candidate_threshold' );
	set_capability_row( 'update_readonly_candidate_threshold' );
	set_capability_row( 'update_candidate_status_threshold' );
	set_capability_row( 'set_view_status_threshold' );
	set_capability_row( 'change_view_status_threshold' );
	set_capability_row( 'show_monitor_list_threshold' );
    set_capability_boolean( 'auto_set_status_to_assigned' );
    set_capability_enum( 'candidate_assigned_status' );
    set_capability_boolean( 'limit_reporters', true );

	# Notes
	set_capability_row( 'add_candidatenote_threshold' );
	set_capability_row( 'update_candidatenote_threshold' );
    set_capability_boolean( 'candidatenote_allow_user_edit_delete' );
	set_capability_row( 'delete_candidatenote_threshold' );
	set_capability_row( 'private_candidatenote_threshold' );


	# Others
	set_capability_row( 'view_changelog_threshold' );
	set_capability_row( 'view_handler_threshold' );
	set_capability_row( 'view_history_threshold' );
	set_capability_row( 'candidate_reminder_threshold' );

?>

<br />
<div align="center">
<?php
	echo lang_get( 'operation_successful' ) . '<br />';
	print_bracket_link( $t_redirect_url, lang_get( 'proceed' ) );
?>
</div>

<?php html_page_bottom1( __FILE__ ) ?>