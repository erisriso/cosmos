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
	# $Id: lang_api.php,v 1.40.4.1 2007-10-13 22:35:32 giallu Exp $
	# --------------------------------------------------------

	### Language (Internationalization) API ##

	# Cache of localization strings in the language specified by the last
	# lang_load call
	$g_lang_strings = array();

	# stack for language overrides
	$g_lang_overrides = array();

	# To be used in custom_strings_inc.php :
	$g_active_language  = '';

	# ------------------
	# Loads the specified language and stores it in $g_lang_strings,
	# to be used by lang_get
	function lang_load( $p_lang ) {
		global $g_lang_strings, $g_active_language;

		$g_active_language  = $p_lang;
		if ( isset( $g_lang_strings[ $p_lang ] ) ) {
			return;
		}

		if ( ! lang_language_exists( $p_lang ) ) {
			return;
		}

		$t_lang_dir = dirname ( dirname ( __FILE__ ) ) . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR;

		require_once( $t_lang_dir . 'strings_' . $p_lang . '.txt' );

		# Allow overriding strings declared in the language file.
		# custom_strings_inc.php can use $g_active_language
		$t_custom_strings = dirname ( dirname( __FILE__ ) ) . DIRECTORY_SEPARATOR . 'custom_strings_inc.php';
		if ( file_exists( $t_custom_strings ) ) {
			require( $t_custom_strings ); # this may be loaded multiple times, once per language
		}

		$t_vars = get_defined_vars();

		foreach ( array_keys( $t_vars ) as $t_var ) {
			$t_lang_var = ereg_replace( '^s_', '', $t_var );
			if ( $t_lang_var != $t_var || 'MANTIS_ERROR' == $t_var ) {
				$g_lang_strings[ $p_lang ][ $t_lang_var ] = $$t_var;
			}
		}
	}

	# ------------------
	# Determine the preferred language
	function lang_get_default() {
		global $g_active_language;

		$t_lang = false;

		# Confirm that the user's language can be determined
		if ( auth_is_user_authenticated() ) {
			$t_lang = user_pref_get_language( auth_get_current_user_id() );
		}

		# Otherwise fall back to default
		if ( false === $t_lang ) {
			$t_lang = config_get( 'default_language' );
		}

		if ( 'auto' == $t_lang ) {
			$t_lang = lang_map_auto();
		}

		# Remember the language
		$g_active_language = $t_lang;

		return $t_lang;
	}

	# ------------------

	function lang_map_auto() {
		$t_lang = config_get( 'fallback_language' );

		if ( isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			$t_accept_langs = explode( ',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
			$t_auto_map = config_get( 'language_auto_map' );

			# Expand language map
			$t_auto_map_exp = array();
			foreach( $t_auto_map as $t_encs => $t_enc_lang ) {
				$t_encs_arr = explode( ',', $t_encs );

				foreach ( $t_encs_arr as $t_enc ) {
					$t_auto_map_exp[ trim( $t_enc ) ] = $t_enc_lang;
				}
			}

			# Find encoding
			foreach ( $t_accept_langs as $t_accept_lang ) {
				$t_tmp = explode( ';', strtolower( $t_accept_lang ) );

				if ( isset( $t_auto_map_exp[ trim( $t_tmp[0] ) ] ) ) {
					$t_valid_langs = config_get( 'language_choices_arr' );
					$t_found_lang = $t_auto_map_exp[ trim( $t_tmp[0] ) ];

					if ( in_array( $t_found_lang, $t_valid_langs, true ) ) {
						$t_lang = $t_found_lang;
						break;
					}
				}
			}
		}

		return $t_lang;
	}

	# Ensures that a language file has been loaded
	function lang_ensure_loaded( $p_lang ) {
		global $g_lang_strings;

		if ( ! isset( $g_lang_strings[ $p_lang ] ) ) {
			lang_load( $p_lang );
		}
	}


	/**
	* Check if the given language exists
	* 
	* @param $p_lang string the language name
	* @return boolean 
	*/
	function lang_language_exists( $p_lang ) {
		$t_valid_langs = config_get( 'language_choices_arr' );
		$t_valid = in_array( $p_lang, $t_valid_langs, true );
		return $t_valid;
	}


	# ------------------
	# language stack implementation
	#
  # push a language onto the stack
  function lang_push( $p_lang=null ) {
		global $g_lang_overrides;

  	# If no specific language is requested, we'll
		#  try to determine the language from the users
		#  preferences

		$t_lang = $p_lang;

		if ( null === $t_lang ) {
			$t_lang = config_get( 'default_language' );
		}

		# don't allow 'auto' as a language to be pushed onto the stack
		#  The results from auto are always the local user, not what the
		#  override wants, unless this is the first language setting
		if ( ( 'auto' == $t_lang ) && ( 0 < count( $g_lang_overrides ) ) ) {
			$t_lang = config_get( 'fallback_language' );
		}

		$g_lang_overrides[] = $t_lang;

		# Remember the language
		$g_active_language = $t_lang;

		# make sure it's loaded
		lang_ensure_loaded( $t_lang );
  }

  # pop a language onto the stack and return it
  function lang_pop( ) {
		global $g_lang_overrides;

		return array_pop( $g_lang_overrides );
  }

  # return value on top of the language stack
  #  return default if stack is empty
  function lang_get_current( ) {
		global $g_lang_overrides;

		$t_count_overrides = count($g_lang_overrides);
		if ($t_count_overrides > 0 ) {
			$t_lang = $g_lang_overrides[ $t_count_overrides - 1];
		} else {
			$t_lang = lang_get_default();
		}

		return $t_lang;
  }




	# ------------------
	# Retrieves an internationalized string
	#  This function will return one of (in order of preference):
	#    1. The string in the current user's preferred language (if defined)
	#    2. The string in English
	function lang_get( $p_string, $p_lang = null ) {
		global $g_lang_strings;

		# If no specific language is requested, we'll
		#  try to determine the language from the users
		#  preferences

		$t_lang = $p_lang;

		if ( null === $t_lang ) {
			$t_lang = lang_get_current();
		}

		# Now we'll make sure that the requested language is loaded

		lang_ensure_loaded( $t_lang );

		# note in the current implementation we always return the same value
		#  because we don't have a concept of falling back on a language.  The
		#  language files actually *contain* English strings if none has been
		#  defined in the correct language
		# @@@ thraxisp - not sure if this is still true. Strings from last language loaded
		#      may still be in memeory if a new language is loaded.

		if ( lang_exists( $p_string, $t_lang ) ) {
			return $g_lang_strings[ $t_lang ][ $p_string];
		} else {
			if ( $t_lang == 'english' ) {
				error_parameters( $p_string );
				trigger_error( ERROR_LANG_STRING_NOT_FOUND, WARNING );
				return '';
			} else {
				# if string is not found in a language other than english, then retry using the english language.
				return lang_get( $p_string, 'english' );
			}
		}
	}

	# ------------------
	# Check the language entry, if found return true, otherwise return false.
	function lang_exists( $p_string, $p_lang ) {
		global $g_lang_strings;

		return ( isset( $g_lang_strings[ $p_lang ] )
			&& isset( $g_lang_strings[ $p_lang ][ $p_string ] ) );
	}

	# ------------------
	# Get language:
	# - If found, return the appropriate string (as lang_get()).
	# - If not found, no default supplied, return the supplied string as is.
	# - If not found, default supplied, return default.
	function lang_get_defaulted( $p_string, $p_default = null, $p_lang = null ) {
		$t_lang = $p_lang;

		if ( null === $t_lang ) {
			$t_lang = lang_get_current();
		}

		# Now we'll make sure that the requested language is loaded
		lang_ensure_loaded( $t_lang );

		if ( lang_exists( $p_string, $t_lang ) ) {
			return lang_get( $p_string );
		} else {
			if ( null === $p_default ) {
				return $p_string;
			} else {
				return $p_default;
			}
		}
	}
?>