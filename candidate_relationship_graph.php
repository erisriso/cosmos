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
	# $Id: candidate_relationship_graph.php,v 1.6.2.1 2007-10-13 22:32:47 giallu Exp $
	# --------------------------------------------------------

	require_once( 'core.php' );

	$t_core_path = config_get( 'core_path' );

	require_once( $t_core_path.'candidate_api.php' );
	require_once( $t_core_path.'compress_api.php' );
	require_once( $t_core_path.'current_user_api.php' );
	require_once( $t_core_path.'relationship_graph_api.php' );

	# If relationship graphs were made disabled, we disallow any access to
	# this script.

	auth_ensure_user_authenticated();

	if ( ON != config_get( 'relationship_graph_enable' ) )
		access_denied();

	$f_candidate_id		= gpc_get_int( 'candidate_id' );
	$f_type			= gpc_get_string( 'graph', 'relation' );
	$f_orientation	= gpc_get_string( 'orientation', config_get( 'relationship_graph_orientation' ) );

	if ( 'relation' == $f_type ) {
		$t_graph_type = 'relation';
		$t_graph_relation = true;
	} else {
		$t_graph_type = 'dependency';
		$t_graph_relation = false;
	}

	if ( 'horizontal' == $f_orientation ) {
		$t_graph_orientation = 'horizontal';
		$t_graph_horizontal = true;
	} else {
		$t_graph_orientation = 'vertical';
		$t_graph_horizontal = false;
	}

	access_ensure_candidate_level( VIEWER, $f_candidate_id );

	$t_candidate = candidate_prepare_display( candidate_get( $f_candidate_id, true ) );

	if( $t_candidate->project_id != helper_get_current_project() ) {
		# in case the current project is not the same project of the candidate we are viewing...
		# ... override the current project. This to avoid problems with categories and handlers lists etc.
		$g_project_override = $t_candidate->project_id;
	}

	compress_enable();

	html_page_top1( candidate_format_summary( $f_candidate_id, SUMMARY_CAPTION ) );
	html_page_top2();
?>
<br />

<table class="width100" cellspacing="1">

<tr>
	<!-- Title -->
	<td class="form-title">
		<?php 
		if ( $t_graph_relation ) 
			echo lang_get( 'viewing_candidate_relationship_graph_title' );
		else
			echo lang_get( 'viewing_candidate_dependency_graph_title' );
		?>
	</td>
	<!-- Links -->
	<td class="right">
		<!-- View Issue -->
		<span class="small"><?php print_bracket_link( 'view.php?id=' . $f_candidate_id, lang_get( 'view_issue' ) ) ?></span>

		<!-- Relation/Dependency Graph Switch -->
		<span class="small">
<?php
		if ( $t_graph_relation )
			print_bracket_link( 'candidate_relationship_graph.php?candidate_id=' . $f_candidate_id . '&amp;graph=dependency', lang_get( 'dependency_graph' ) );
		else
			print_bracket_link( 'candidate_relationship_graph.php?candidate_id=' . $f_candidate_id . '&amp;graph=relation', lang_get( 'relation_graph' ) );
?>
		</span>
<?php
		if ( !$t_graph_relation ) {
?>
		<!-- Horizontal/Vertical Switch -->
		<span class="small">
<?php
			if ( $t_graph_horizontal )
				print_bracket_link( 'candidate_relationship_graph.php?candidate_id=' . $f_candidate_id . '&amp;graph=dependency&orientation=vertical', lang_get( 'vertical' ) );
			else
				print_bracket_link( 'candidate_relationship_graph.php?candidate_id=' . $f_candidate_id . '&amp;graph=dependency&orientation=horizontal', lang_get( 'horizontal' ) );
?>
		</span>
<?php
		}
?>
	</td>
</tr>

<tr>
	<!-- Graph -->
	<td colspan="2">
<?php
	if ( $t_graph_relation )
		$t_graph = relgraph_generate_rel_graph( $f_candidate_id, $t_candidate );
	else
		$t_graph = relgraph_generate_dep_graph( $f_candidate_id, $t_candidate, $t_graph_horizontal );

	relgraph_output_map( $t_graph, 'relationship_graph_map' );
?>
		<div class="center relationship-graph">
			<img src="candidate_relationship_graph_img.php?candidate_id=<?php echo $f_candidate_id ?>&amp;graph=<?php echo $t_graph_type ?>&orientation=<?php echo $t_graph_orientation ?>"
				border="0" usemap="#relationship_graph_map" />
		</div>
	</td>
</tr>

<tr>
	<!-- Legend -->
	<td colspan="2">
		<table class="hide">
		<tr>
			<td class="center">
				<img alt="" src="images/rel_related.png" />
				<?php echo lang_get( 'related_to' ) ?>
			</td>
			<td class="center">
				<img alt="" src="images/rel_dependant.png" />
				<?php echo lang_get( 'blocks' ) ?>
			</td>
			<td class="center">
				<img alt="" src="images/rel_duplicate.png" />
				<?php echo lang_get( 'duplicate_of' ) ?>
			</td>
		</tr>
		</table>
	</td>
</tr>

</table>

<br />

<?php
	include( 'candidate_view_inc.php' );
	html_page_bottom1( __FILE__ );
?>
