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
	# $Id: summary_graph_byreporter.php,v 1.14.22.1 2007-10-13 22:34:34 giallu Exp $
	# --------------------------------------------------------
?>
<?php
	require_once( 'core.php' );

	$t_core_path = config_get( 'core_path' );

	require_once( $t_core_path.'graph_api.php' );

	access_ensure_project_level( config_get( 'view_summary_threshold' ) );

	$f_width = gpc_get_int( 'width', 300 );
	$t_ar = config_get( 'graph_bar_aspect' );

	$t_metrics = pipeline_stats('pie');
	graph_pie( $t_metrics, lang_get( 'rejected_per_phase' ) .' (%)', $f_width, $f_width * $t_ar );

?>
