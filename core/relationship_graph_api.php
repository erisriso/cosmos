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
	# $Id: relationship_graph_api.php,v 1.6.2.1 2007-10-13 22:35:42 giallu Exp $
	# --------------------------------------------------------

	### Relationship Graph API ###

	$t_core_dir = dirname( __FILE__ ).DIRECTORY_SEPARATOR;

	require_once( $t_core_dir . 'relationship_api.php' );
	require_once( $t_core_dir . 'graphviz_api.php' );

	# ================================================================
	# Author: Juliano Ravasi Ferraz <jferraz at users sourceforge net>
	# ================================================================
	#
	# This uses GraphViz utilities to generate relationship graphs for
	# issues. Either GraphViz (for all OSs except Windows) or
	# WinGraphviz (for Windows) must be installed in order to use this
	# feature.
	#
	# Graphviz is available at:
	#  - http://www.graphviz.org/
	#  - http://www.research.att.com/sw/tools/graphviz/
	#
	# WinGraphviz is available at:
	#  - http://home.so-net.net.tw/oodtsen/wingraphviz/
	#
	# Most Linux distributions already have a GraphViz package
	# conveniently available for download and install. Refer to
	# config_defaults_inc.php for how to enable this feature once
	# GraphViz is installed.

	# --------------------
	# Generate a relationship graph for the given issue.
	function relgraph_generate_rel_graph( $p_candidate_id, $p_candidate = null ) {

		# List of visited issues and their data.
		$v_candidate_list = array ( );
		$v_rel_list = array ( );

		# Queue for breadth-first
		$v_queue = array ( );

		# Now we visit all related issues.
		$t_max_depth		= config_get( 'relationship_graph_max_depth' );

		# Put the first element into queue.
		array_push( $v_queue, array ( 0, $p_candidate_id ) );

		# And now we proccess it
		while ( !empty( $v_queue ) ) {
			list( $t_depth, $t_id ) = array_shift( $v_queue );

			if ( isset( $v_candidate_list[$t_id] ) )
				continue;

			if ( !candidate_exists( $t_id ) )
				continue;

			if ( !access_has_candidate_level( VIEWER, $t_id ) )
				continue;

			$v_candidate_list[$t_id] = candidate_prepare_display( candidate_get( $t_id, false ) );

			$t_relationships	= relationship_get_all_src( $t_id );
			foreach ( $t_relationships as $t_relationship ) {
				$t_dst = $t_relationship->dest_candidate_id;
				if ( BUG_DEPENDANT == $t_relationship->type ) {
					$v_rel_list[$t_id][$t_dst] = BUG_DEPENDANT;
					$v_rel_list[$t_dst][$t_id] = BUG_BLOCKS;
				} else {
					$v_rel_list[$t_id][$t_dst] = $t_relationship->type;
					$v_rel_list[$t_dst][$t_id] = $t_relationship->type;
				}

				if ( $t_depth < $t_max_depth )
					array_push( $v_queue, array ( $t_depth + 1, $t_dst ) );
			}

			$t_relationships	= relationship_get_all_dest( $t_id );
			foreach ( $t_relationships as $t_relationship ) {
				$t_dst = $t_relationship->src_candidate_id;
				if ( BUG_DEPENDANT == $t_relationship->type ) {
					$v_rel_list[$t_id][$t_dst] = BUG_BLOCKS;
					$v_rel_list[$t_dst][$t_id] = BUG_DEPENDANT;
				} else {
					$v_rel_list[$t_id][$t_dst] = $t_relationship->type;
					$v_rel_list[$t_dst][$t_id] = $t_relationship->type;
				}

				if ( $t_depth < $t_max_depth )
					array_push( $v_queue, array ( $t_depth + 1, $t_dst ) );
			}
		}

		# We have already collected all the information we need to generate
		# the graph. Now it is the matter to create a Digraph object and
		# store the information there, along with graph formatting attributes.
		$t_id_string		= candidate_format_id( $p_candidate_id );
		$t_graph_fontname	= config_get( 'relationship_graph_fontname' );
		$t_graph_fontsize	= config_get( 'relationship_graph_fontsize' );
		$t_graph_fontpath	= config_get( 'system_font_folder' );
		$t_view_on_click	= config_get( 'relationship_graph_view_on_click' );
		$t_neato_tool		= config_get( 'neato_tool' );

		$t_graph_attributes	= array ( );

		if ( !empty( $t_graph_fontpath ) )
			$t_graph_attributes['fontpath'] = $t_graph_fontpath;

		$t_graph = new Graph( $t_id_string, $t_graph_attributes, $t_neato_tool );

		$t_graph->set_default_node_attr( array (
			'fontname'	=> $t_graph_fontname,
			'fontsize'	=> $t_graph_fontsize,
			'shape'		=> 'record',
			'style'		=> 'filled',
			'height'	=> '0.2',
			'width'		=> '0.4'
		) );

		$t_graph->set_default_edge_attr( array (
			'style'		=> 'solid',
			'color'		=> '#0000C0',
			'dir'		=> 'none'
		) );

		# Add all issue nodes and edges to the graph.
		ksort( $v_candidate_list );
		foreach ( $v_candidate_list as $t_id => $t_candidate ) {
			$t_id_string	= candidate_format_id( $t_id );

			if ( $t_view_on_click )
				$t_url = string_get_candidate_view_url( $t_id );
			else
				$t_url = 'candidate_relationship_graph.php?candidate_id=' . $t_id . '&amp;graph=relation';

			relgraph_add_candidate_to_graph( $t_graph, $t_id_string, $t_candidate,
									   $t_url, $t_id == $p_candidate_id );

			# Now add all relationship edges to the graph.
			if ( isset( $v_rel_list[$t_id] ) ) {
				foreach ( $v_rel_list[$t_id] as $t_dst => $t_relation ) {
					# Do not create edges for unvisited candidates.
					if ( !isset( $v_candidate_list[$t_dst] ) )
						continue;

					# avoid double links
					if ( $t_dst < $t_id )
						continue;

					$t_related_id	= candidate_format_id( $t_dst );

          global $g_relationships;
          if ( isset( $g_relationships[ $t_relation ] ) && isset( $g_relationships[ $t_relation ][ '#edge_style' ] ) ) {
            $t_edge_style = $g_relationships[ $t_relation ][ '#edge_style' ];
          } else {
            $t_edge_style = array ( );
          }

					$t_graph->add_edge( $t_id_string, $t_related_id, $t_edge_style );
				}
			}
		}

		return $t_graph;
	}


	# --------------------
	# Generate a dependency relationship graph for the given issue.
	function relgraph_generate_dep_graph( $p_candidate_id, $p_candidate = null, $p_horizontal = false ) {

		# List of visited issues and their data.
		$v_candidate_list = array ( );

		# Firstly, we visit all ascendant issues and all descendant issues
		# and collect all the necessary data in the $v_candidate_list variable.
		# We do not visit other descendants of our parents, neither other
		# ascendants of our children, to avoid displaying too much unrelated
		# issues. We still collect the information about those relationships,
		# so, if these issues happen to be visited also, relationship links
		# will be preserved.

		# The first issue in the list is the one we are parting from.
		if ( null === $p_candidate )
			$p_candidate = candidate_prepare_display( candidate_get( $p_candidate_id, true ) );

		$v_candidate_list[$p_candidate_id] = $p_candidate;
		$v_candidate_list[$p_candidate_id]->is_descendant = true;
		$v_candidate_list[$p_candidate_id]->parents = array ( );
		$v_candidate_list[$p_candidate_id]->children = array ( );

		# Now we visit all ascendants of the root issue.
		$t_relationships = relationship_get_all_dest( $p_candidate_id );
		foreach ( $t_relationships as $t_relationship ) {
			if ( $t_relationship->type != BUG_DEPENDANT )
				continue;

			$v_candidate_list[$p_candidate_id]->parents[] = $t_relationship->src_candidate_id;
			relgraph_add_parent( $v_candidate_list, $t_relationship->src_candidate_id );
		}

		$t_relationships = relationship_get_all_src( $p_candidate_id );
		foreach ( $t_relationships as $t_relationship ) {
			if ( $t_relationship->type != BUG_DEPENDANT )
				continue;

			$v_candidate_list[$p_candidate_id]->children[] = $t_relationship->dest_candidate_id;
			relgraph_add_child( $v_candidate_list, $t_relationship->dest_candidate_id );
		}

		# We have already collected all the information we need to generate
		# the graph. Now it is the matter to create a Digraph object and
		# store the information there, along with graph formatting attributes.
		$t_id_string		= candidate_format_id( $p_candidate_id );
		$t_graph_fontname	= config_get( 'relationship_graph_fontname' );
		$t_graph_fontsize	= config_get( 'relationship_graph_fontsize' );
		$t_graph_fontpath	= config_get( 'system_font_folder' );
		$t_view_on_click	= config_get( 'relationship_graph_view_on_click' );
		$t_dot_tool			= config_get( 'dot_tool' );

		$t_graph_attributes	= array ( );

		if ( !empty( $t_graph_fontpath ) )
			$t_graph_attributes['fontpath'] = $t_graph_fontpath;

		if ( $p_horizontal ) {
			$t_graph_attributes['rankdir'] = 'LR';
			$t_graph_orientation = 'horizontal';
		} else {
			$t_graph_orientation = 'vertical';
		}

		$t_graph = new Digraph( $t_id_string, $t_graph_attributes, $t_dot_tool );

		$t_graph->set_default_node_attr( array (
			'fontname'	=> $t_graph_fontname,
			'fontsize'	=> $t_graph_fontsize,
			'shape'		=> 'record',
			'style'		=> 'filled',
			'height'	=> '0.2',
			'width'		=> '0.4'
		) );

		$t_graph->set_default_edge_attr( array (
			'style'		=> 'solid',
			'color'		=> '#C00000',
			'dir'		=> 'back'
		) );

		# Add all issue nodes and edges to the graph.
		foreach ( $v_candidate_list as $t_related_candidate_id => $t_related_candidate ) {
			$t_id_string	= candidate_format_id( $t_related_candidate_id );

			if ( $t_view_on_click )
				$t_url = string_get_candidate_view_url( $t_related_candidate_id );
			else
				$t_url = 'candidate_relationship_graph.php?candidate_id=' . $t_related_candidate_id . '&amp;graph=dependency&orientation=' . $t_graph_orientation;

			relgraph_add_candidate_to_graph( $t_graph, $t_id_string, $t_related_candidate,
									   $t_url, $t_related_candidate_id == $p_candidate_id );

			# Now add all relationship edges to the graph.
			foreach ( $v_candidate_list[$t_related_candidate_id]->parents as $t_parent_id ) {
				# Do not create edges for unvisited candidates.
				if ( !isset( $v_candidate_list[$t_parent_id] ) )
					continue;

				$t_parent_node	= candidate_format_id( $t_parent_id );
				$t_graph->add_edge( $t_parent_node, $t_id_string );
			}
		}

		return $t_graph;
	}


	# --------------------
	# Internal function used to visit ascendant issues recursively.
	function relgraph_add_parent( &$p_candidate_list, $p_candidate_id ) {

		# If the issue is already in the list, we already visited it, just
		# leave.
		if ( isset( $p_candidate_list[$p_candidate_id] ) )
			return true;

		# Check if the issue really exists and we have access to it. If not,
		# it is like it didn't exist.
		if ( !candidate_exists( $p_candidate_id ) )
			return false;

		if ( !access_has_candidate_level( VIEWER, $p_candidate_id ) )
			return false;

		# Add the issue to the list.
		$p_candidate_list[$p_candidate_id] = candidate_prepare_display( candidate_get( $p_candidate_id, false ) );
		$p_candidate_list[$p_candidate_id]->is_descendant = false;
		$p_candidate_list[$p_candidate_id]->parents = array ( );
		$p_candidate_list[$p_candidate_id]->children = array ( );

		# Add all parent issues to the list of parents and visit them
		# recursively.
		$t_relationships = relationship_get_all_dest( $p_candidate_id );
		foreach ( $t_relationships as $t_relationship ) {
			if ( $t_relationship->type != BUG_DEPENDANT )
				continue;

			$p_candidate_list[$p_candidate_id]->parents[] = $t_relationship->src_candidate_id;
			relgraph_add_parent( $p_candidate_list, $t_relationship->src_candidate_id );
		}

		# Add all child issues to the list of children. Do not visit them
		# since this will add too much data that is unrelated to the original
		# issue, and has a potential to generate really huge graphs.
		$t_relationships = relationship_get_all_src( $p_candidate_id );
		foreach ( $t_relationships as $t_relationship ) {
			if ( $t_relationship->type != BUG_DEPENDANT )
				continue;

			$p_candidate_list[$p_candidate_id]->children[] = $t_relationship->dest_candidate_id;
		}

		return true;
	}


	# --------------------
	# Internal function used to visit descendant issues recursively.
	function relgraph_add_child( &$p_candidate_list, $p_candidate_id ) {

		# Check if the issue is already in the issue list.
		if ( isset( $p_candidate_list[$p_candidate_id] ) ) {

			# The issue is in the list, but we cannot discard it since it
			# may be a parent issue (whose children were not visited).

			if ( !$p_candidate_list[$p_candidate_id]->is_descendant ) {
				# Yes, we visited this issue as a parent... This is the case
				# where someone set up a cyclic relationship (I really hope
				# nobody ever do this, but should keep sanity) for this
				# issue. We just have to finish the job, visiting all issues
				# that were already listed by _add_parent().
				$p_candidate_list[$p_candidate_id]->is_descendant = true;

				foreach ( $p_candidate_list[$p_candidate_id]->children as $t_child )
					relgraph_add_child( $p_candidate_id, $t_child );
			}
		} else {
			# The issue is not in the list, proceed as usual.

			# Check if the issue really exists and we have access to it.
			# If not, it is like it didn't exist.
			if ( !candidate_exists( $p_candidate_id ) )
				return false;

			if ( !access_has_candidate_level( VIEWER, $p_candidate_id ) )
				return false;

			# Add the issue to the list.
			$p_candidate_list[$p_candidate_id] = candidate_prepare_display( candidate_get( $p_candidate_id, false ) );
			$p_candidate_list[$p_candidate_id]->is_descendant = true;
			$p_candidate_list[$p_candidate_id]->parents = array ( );
			$p_candidate_list[$p_candidate_id]->children = array ( );

			# Add all parent issues to the list of parents. Do not visit them
			# for the same reason we didn't visit the children of all
			# ancestors.
			$t_relationships = relationship_get_all_dest( $p_candidate_id );
			foreach ( $t_relationships as $t_relationship ) {
				if ( $t_relationship->type != BUG_DEPENDANT )
					continue;

				$p_candidate_list[$p_candidate_id]->parents[] = $t_relationship->src_candidate_id;
			}

			# Add all child issues to the list of children and visit them
			# recursively.
			$t_relationships = relationship_get_all_src( $p_candidate_id );
			foreach ( $t_relationships as $t_relationship ) {
				if ( $t_relationship->type != BUG_DEPENDANT )
					continue;

				$p_candidate_list[$p_candidate_id]->children[] = $t_relationship->dest_candidate_id;
				relgraph_add_child( $p_candidate_list, $t_relationship->dest_candidate_id );
			}
		}

		return true;
	}


	# --------------------
	# Outputs a png image for the given relationship graph, previously
	# generated by relgraph_generate_graph_for_candidate().
	function relgraph_output_image( $p_graph ) {
		$p_graph->output( 'png', true );
	}


	# --------------------
	# Outputs an image map in HTML form (too bad dot didn't output XHTML...)
	# for the given relationship graph, previously generated by
	# relgraph_generate_graph_for_candidate().
	function relgraph_output_map( $p_graph, $p_name ) {
		echo '<map name="' . $p_name . '">' . "\n";
		$p_graph->output( 'cmap' );
		echo '</map>' . "\n";
	}


	# --------------------
	# Internal function used to add a candidate to the given graph.
	function relgraph_add_candidate_to_graph( &$p_graph, $p_candidate_id, $p_candidate, $p_url = null, $p_highlight = false ) {
		$t_node_attributes = array ( );
		$t_node_attributes['label']		= $p_candidate_id;

		if ( $p_highlight ) {
			$t_node_attributes['color'] = '#0000FF';
			$t_node_attributes['style'] = 'bold, filled';
		} else {
			$t_node_attributes['color'] = 'black';
			$t_node_attributes['style'] = 'filled';
		}

		$t_node_attributes['fillcolor']	= get_status_color( $p_candidate->status );

		if ( null !== $p_url )
			$t_node_attributes['URL']	= $p_url;

		$t_summary		= $p_candidate->summary;
		$t_status		= get_enum_element( 'status', $p_candidate->status );
		$t_node_attributes['tooltip']	= '[' . $t_status . '] ' . $t_summary;

		$p_graph->add_node( $p_candidate_id, $t_node_attributes );
	}

?>
