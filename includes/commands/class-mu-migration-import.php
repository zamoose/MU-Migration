<?php
/**
 *  @package TenUp\MU_Migration
 */
namespace TenUp\MU_Migration\Commands;
use TenUp\MU_Migration\Helpers;
use WP_CLI;
use Alchemy\Zippy\Zippy;

class ImportCommand extends MUMigrationBase {

	/**
	 * Imports all users from .csv file
	 * This command will create a map file containing the new user_id for each user, we do this because with this map file
	 * we can update the post_author of all posts with the corresponding new user ID.
	 *
	 * ## OPTIONS
	 *
	 * <inputfile>
	 * : The name of the exported .csv file
	 *
	 * ## EXAMBLES
	 *
	 *   wp mu-migration import users users.csv --map_file=ids_maps.json
	 *
	 * @synopsis <inputfile> --map_file=<map> --blog_id=<blog_id>
	 */
	public function users( $args = array(), $assoc_args = array(), $verbose = true ) {
		global $wpdb;

		$this->process_args(
			array(
				0 => '', // .csv to import users
			),
			$args,
			array(
				'blog_id' => '',
				'map_file' => 'ids_maps.json',
			),
			$assoc_args
		);


		$filename = $this->args[0];

		if ( empty( $filename ) || ! file_exists( $filename ) ) {
			WP_CLI::error( __( "Invalid input file", 'mu-migration') );
		}

		if ( empty( $this->assoc_args[ 'blog_id' ]) ) {
			WP_CLI::error( __( 'Please, provide a blog_id ', 'mu-migration') );
		}

		if ( ! is_multisite() ) {
			WP_CLI::error( __( 'You should be running multisite in order to run this command', 'mu-migration' ) );
		}

		$input_file_handler = fopen( $filename, 'r');

		$delimiter = ',';

		/**
		 * This array will hold the new id for each old id
		 *
		 * Ex:
		 * array(
		 *  'OLD_ID' => 'NEW_ID'
		 * )
		 *
		 */
		$ids_maps = array();
		$count = 0;
		$existing_users = 0;
		$labels = array();

		if ( false !== $input_file_handler ) {
			$this->line( sprintf( "Parsing %s...", $filename ), $verbose );

			$line = 0;

			switch_to_blog( $this->assoc_args[ 'blog_id' ] );
			wp_suspend_cache_addition( true );
			while( ( $data = fgetcsv( $input_file_handler, 0, $delimiter ) ) !== false ) {
				//read the labels and skip
				if ( $line++ == 0 ) {
					$labels = $data;
					continue;
				}

				$user_data = array_combine( $labels, $data );

				$old_id = $user_data['ID'];
				unset($user_data['ID']);

				$user_exists = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->users} WHERE user_login = %s OR user_email = %s;",
						$user_data['user_login'],
						$user_data['user_email']
					)
				);

				$user_exists = $user_exists[0];

				if ( ! $user_exists ) {

					/*
					 * wp_insert_users accepts only the default user meta keys
					 */
					$default_user_data = array();
					foreach( ExportCommand::getCSVHeaders() as $key ) {
						if ( isset( $user_data[ $key ] ) ) {
							$default_user_data[ $key ] = $user_data[ $key ];
						}
					}

					//All custom user meta data
					$user_meta_data = array_diff_assoc( $user_data, $default_user_data );

					$new_id = wp_insert_user( $default_user_data );

					if ( ! is_wp_error( $new_id ) ) {
						$wpdb->update( $wpdb->users, array( 'user_pass' => $user_data['user_pass'] ), array( 'ID' => $new_id ) );

						$user = new \WP_User( $new_id );

						//Inserts all custom meta data
						foreach( $user_meta_data as $meta_key => $meta_value ) {
							update_user_meta( $new_id, $meta_key, maybe_unserialize( $meta_value ) );
						}

						/**
						 * Fires an action before exporting the custom user data
						 *
						 * @sinec 0.1.0
						 *
						 * @param Array $user_data The $user_data array
						 * @param WP_User $user The user object
						 */
						do_action( 'mu_migration/import/user/custom_data_before', $user_data, $user );

						/**
						 * Modify the default set of user data to be exported/imported
						 *
						 * @since 0.1.0
						 *
						 * @param Array
						 * @param WP_User $user object for the current user
						 */
						$custom_user_data = apply_filters( 'mu_migration/export/user/data', array(), $user );

						if ( ! empty( $custom_user_data ) ) {
							foreach( $custom_user_data as $meta_key => $meta_value ) {
								if ( isset( $user_data[$meta_key] ) ) {
									update_user_meta( $new_id, $meta_key, sanitize_text_field( $meta_value ) );
								}
							}
						}

						/**
						 * Fires an action after exporting the custom user data
						 *
						 * @since 0.1.0
						 *
						 * @param Array $user_data The $user_data array
						 * @param WP_User $user The user object
						 */
						do_action( 'mu_migration/import/user/custom_data_after', $user_data, $user );

						$count++;
						$ids_maps[ $old_id ] = $new_id;
						Helpers\light_add_user_to_blog( $this->assoc_args[ 'blog_id' ], $new_id, $user_data['role'] );
					} else {
						$this->warning( sprintf(
							__( 'An error has occurred when inserting %s: %s.', 'mu-migration'),
							$user_data['user_login'] ,
							implode( ', ', $new_id->get_error_messages() )
						), $verbose );
					}
				} else {
					$this->warning( sprintf(
						__( '%s exists, using his ID (%d)...', 'mu-migration'),
						$user_data['user_login'],
						$user_exists
					), $verbose );

					$existing_users++;
					$ids_maps[ $old_id ] = $user_exists;
					Helpers\light_add_user_to_blog( $this->assoc_args[ 'blog_id' ], $user_exists, $user_data['role'] );
				}

				unset( $user_exists );
				unset( $user_data );
				unset( $data );
			}

			wp_suspend_cache_addition( false );
			restore_current_blog();

			if ( ! empty( $ids_maps ) ) {
				//Saving the ids_maps to a file
				$output_file_handler = fopen( $this->assoc_args['map_file'], 'w+' );
				fwrite( $output_file_handler, json_encode( $ids_maps ) );
				fclose( $output_file_handler );

				$this->success( sprintf(
					__( 'A map file has been created: %s', 'mu-migration' ),
					$this->assoc_args['map_file']
				), $verbose );
			}

			$this->success( sprintf(
				__( '%d users have been imported and %d users already existed', 'mu-migration' ),
				absint( $count ),
				absint( $existing_users )
			), $verbose );
		} else {
			WP_CLI::error( sprintf(
				__( 'Can not read the file %s', 'mu-migration' ),
				$filename
			) );
		}
	}

	/**
	 * Imports the tables from a single site instance
	 *
	 * This command will perform the search-replace as well as the necessary updates to make the new tables work with
	 * multisite
	 *
	 * ## OPTIONS
	 *
	 * <inputfile>
	 * : The name of the exported .sql file
	 *
	 * ## EXAMBLES
	 *
	 *   wp mu-migration import tables site.sql --old_prefix=wp_ --old_url=old_domain.com --new_url=new_domain.com
	 *
	 * @synopsis <inputfile> --blog_id=<blog_id> --old_prefix=<old> --new_prefix=<new> [--old_url=<olddomain>] [--new_url=<newdomain>]
	 */
	public function tables( $args = array(), $assoc_args = array(), $verbose = true ) {
		global $wpdb;

		$this->process_args(
			array(
				0 => '', // .sql file to import
			),
			$args,
			array(
				'blog_id'       => '',
				'old_url'       => '',
				'new_url'       => '',
				'old_prefix'    => $wpdb->prefix,
				'new_prefix'	=> ''
			),
			$assoc_args
		);


		$filename = $this->args[0];

		if ( empty( $filename ) || ! file_exists( $filename ) ) {
			WP_CLI::error( __( "Invalid input file", 'mu-migration') );
		}


		if ( empty( $this->assoc_args[ 'blog_id' ] ) ) {
			WP_CLI::error( __( 'Please, provide a blog_id ', 'mu-migration') );
		}

		if ( ! is_multisite() ) {
			WP_CLI::error( __( 'You should be running multisite in order to run this command', 'mu-migration' ) );
		}

		//terminates the script if sed is not installed
		$this->check_for_sed_presence( true );

		//replaces the db prefix and saves back the modifications to the sql file
		if ( ! empty( $this->assoc_args['new_prefix'] ) ) {
			$this->replace_db_prefix( $filename, $this->assoc_args['old_prefix'], $this->assoc_args['new_prefix'] );
		}

		$import = \WP_CLI::launch_self(
			"db import",
			array( $filename ),
			array(),
			false,
			false,
			array()
		);

		if ( 0 === $import ) {
			$this->log( __( 'Database imported', 'mu-migration' ), $verbose );

			//perform search and replace
			if ( ! empty( $this->assoc_args['old_url'] ) && ! empty( $this->assoc_args['new_url'] ) ) {
				$this->log( __( 'Running search-replace', 'mu-migration' ), $verbose);

				$old_url = Helpers\parse_url_for_search_replace( $this->assoc_args['old_url'] );
				$new_url = Helpers\parse_url_for_search_replace( $this->assoc_args['new_url'] );

				$search_replace = \WP_CLI::launch_self(
					"search-replace",
					array(
						$old_url,
						$new_url,
					),
					array(),
					false,
					false,
					array( 'url' => $new_url )
				);

				if ( 0 === $search_replace ) {
					$this->log( __( 'Search and Replace has been successfully executed', 'mu-migration' ), $verbose );
				}

				$this->log( __( 'Running Search and Replace for uploads paths', 'mu-migration' ), $verbose );

                /*
                 * If the $blog_id equals 1 the upload path remains the same
                 */
                if ( $this->assoc_args['blog_id'] > 1 ) {
                    $search_replace = \WP_CLI::launch_self(
                        "search-replace",
                        array( 'wp-content/uploads', 'wp-content/uploads/sites/' . $this->assoc_args['blog_id'] ),
                        array(),
                        false,
                        false,
                        array( 'url' => $new_url )
                    );

                    if ( 0 === $search_replace ) {
                        $this->log( __( 'Uploads paths have been successfully updated', 'mu-migration' ), $verbose );
                    }
                }
			}

			switch_to_blog( (int) $this->assoc_args['blog_id'] );

			//Update the new tables to work properly with Multisite

			$new_wp_roles_option_key = $wpdb->prefix . 'user_roles';
			$old_wp_roles_option_key = $this->assoc_args['old_prefix'] . 'user_roles';

			//Updating user_roles option key
			$wpdb->update(
				$wpdb->options,
				array(
					'option_name' => $new_wp_roles_option_key
				),
				array(
					'option_name' => $old_wp_roles_option_key
				),
				array(
					'%s'
				),
				array(
					'%s'
				)
			);

			restore_current_blog();
		}
	}

	/**
	 * Import a new site into multisite from a zip package
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The name of the exported .zip file
	 *
	 * ## EXAMBLES
	 *
	 *      wp mu-migration import all site.zip
	 *
	 * @synopsis <zipfile> [--blog_id=<blog_id>] [--new_url=<new_url>] [--verbose]
	 */
	public function all( $args = array(), $assoc_args = array() ) {
		$this->process_args(
			array(),
			$args,
			array(
				'blog_id' 	=> '',
				'new_url'	=> ''
			),
			$assoc_args
		);

		$verbose = false;

		if ( isset( $assoc_args['verbose'] ) ) {
			$verbose = true;
		}

		$assoc_args = $this->assoc_args;

		$filename = $this->args[0];

		if ( ! Helpers\is_zip_file( $filename ) ) {
			WP_CLI::error( __( 'The provided file does not appear to be a zip file', 'mu-migration' ) );
		}

		$temp_dir = 'mu-migration' . time() . '/';

		WP_CLI::log( __( 'Extracting zip package...', 'mu-migration' ) );

		/*
		 * Extract the file to the $temp_dir
		 */
		Helpers\extract( $filename, $temp_dir );

		/*
		 * Looks for required (.json, .csv and .sql) files and for the optional folders
		 * that can live in the zip package (plugins, themes and uploads).
		 */
		$site_meta_data     = glob( $temp_dir . '/*.json' 	);
		$users      		= glob( $temp_dir . '/*.csv' 	);
		$sql 				= glob( $temp_dir . '/*.sql' 	);
		$plugins_folder 	= glob( $temp_dir . '/wp-content/plugins' );
		$themes_folder 		= glob( $temp_dir . '/wp-content/themes'  );
		$uploads_folder 	= glob( $temp_dir . '/wp-content/uploads' );

		if ( empty( $site_meta_data ) || empty( $users)  || empty( $sql ) ) {
			WP_CLI::error( __( "There's something wrong with your zip package, unable to find required files", 'mu-migration' ) );
		}

		$site_meta_data = json_decode( file_get_contents( $site_meta_data[0] ) );

		$old_url = $site_meta_data->url;

		if ( ! empty( $assoc_args[ 'new_url' ] ) ) {
			$site_meta_data->url = $assoc_args['new_url'];
		}

		$blog_id = $this->create_new_site( $site_meta_data );

		if ( ! $blog_id ) {
			WP_CLI::error( __( 'Unable to create new site', 'mu-migration' ) );
		}

		$tables_assoc_args = array(
			'blog_id'		=> $blog_id,
			'old_prefix'	=> $site_meta_data->db_prefix,
			'new_prefix'	=> Helpers\get_db_prefix( $blog_id )
		);

		/*
		 * If changing URL, then set the proper params to force search-replace in the tables method
		 */
		if ( ! empty( $assoc_args[ 'new_url' ] ) ) {
			$tables_assoc_args['new_url'] = esc_url( $assoc_args['new_url'] );
			$tables_assoc_args['old_url'] = esc_url( $old_url );
		}

		WP_CLI::log( __( 'Importing tables...', 'mu-migration' ) );

		$this->tables( array( $sql[0] ), $tables_assoc_args, $verbose );

		$map_file = $temp_dir . '/users_map.json';

		$users_assoc_args = array(
			'map_file'	=> $map_file,
			'blog_id'	=> $blog_id
		);

		WP_CLI::log( "Moving files..." );

		if ( ! empty( $plugins_folder ) ) {
			$this->move_plugins( $plugins_folder[0] );
		}

		if ( ! empty( $uploads_folder ) ) {
			$this->move_uploads( $uploads_folder[0], $blog_id );
		}

		if ( ! empty( $themes_folder ) ) {
			$this->move_themes( $themes_folder[0] );
		}

		WP_CLI::log( __( 'Importing Users...', 'mu-migration' ) );

		$this->users( array( $users[0] ), $users_assoc_args, $verbose );

		if ( file_exists( $map_file ) ) {
			WP_CLI::log( __( 'Updating post_author...', 'mu-migration' ) );

			$postsCommand = new PostsCommand();

			$postsCommand->update_author(
				array( $map_file ),
				array(
					'blog_id' => $blog_id,
				),
				$verbose
			);
		}

		WP_CLI::log( __( 'Flushing rewrite rules...', 'mu-migration' ) );

		add_action( 'init', function() use ( $blog_id ) {
			/*
			 * Flush the rewrite rules for the newly created site, just in case
			 */
			switch_to_blog( $blog_id );
			flush_rewrite_rules();
			restore_current_blog();
		}, 9999 );

		WP_CLI::log( __( 'Removing temporary files....', 'mu-migration' ) );

		Helpers\delete_folder( $temp_dir );

		WP_CLI::success( sprintf(
			__( 'All done, your new site is available at %s. Remember to flush the cache (memcache, redis etc).', 'mu-migration' ),
			esc_url( $site_meta_data->url )
		) );

	}

	/**
	 * Moves the plugins to the right directory
	 *
	 * @param $plugins_dir The path to the plugins to be moved over
	 */
	private function move_plugins( $plugins_dir ) {
		if ( file_exists( $plugins_dir ) ){
			WP_CLI::log( 'Moving Plugins...' );
			$plugins 			= new \DirectoryIterator( $plugins_dir );
			$installed_plugins 	= WP_PLUGIN_DIR;

			foreach( $plugins as $plugin ) {
				if ( $plugin->isDir() ) {
					$fullPluginPath = $plugins_dir . '/' . $plugin->getFilename();

					if ( ! file_exists( $installed_plugins . '/' . $plugin->getFilename() ) ) {
						WP_CLI::log( sprintf( __( 'Moving %s to plugins folder' ), $plugin->getFilename() ) );
						rename( $fullPluginPath, $installed_plugins .'/' . $plugin->getFilename() );
					}
				}
			}
		}
	}

	/**
	 * Moves the uploads folder to the right location
	 *
	 * @param $uploads_dir
	 */
	private function move_uploads( $uploads_dir, $blog_id ) {
		if ( file_exists( $uploads_dir ) ){
			\WP_CLI::log( 'Moving Uploads...' );
			switch_to_blog( $blog_id );
			$dest_uploads_dir = wp_upload_dir();
			restore_current_blog();

			Helpers\move_folder( $uploads_dir, $dest_uploads_dir['basedir'] );
		}
	}

	/**
	 * Moves the themes to the right location
	 *
	 * @param $themes_dir
	 */
	private function move_themes( $themes_dir ) {
		if ( file_exists( $themes_dir ) ){
			WP_CLI::log( 'Moving Themes...' );
			$themes 			= new \DirectoryIterator( $themes_dir );
			$installed_themes 	= get_theme_root();

			foreach( $themes as $theme ) {
				if ( $theme->isDir() ) {
					$fullPluginPath = $themes_dir . '/' . $theme->getFilename();

					if ( ! file_exists( $installed_themes . '/' . $theme->getFilename() ) ) {
						WP_CLI::log( sprintf( __( 'Moving %s to themes folder' ), $theme->getFilename() ) );
						rename( $fullPluginPath, $installed_themes .'/' . $theme->getFilename() );

						WP_CLI::launch_self(
							"theme enable",
							array( $theme->getFilename() ),
							array(),
							false,
							false,
							array()
						);
					}
				}
			}
		}
	}

	/**
	 * Creates a new site within multisite
	 *
	 * @param $meta_data
	 * @return bool|false|int
	 */
	private function create_new_site( $meta_data ) {
		$parsed_url = parse_url( esc_url( $meta_data->url ) );
		$site_id 	= 1;

		if ( domain_exists( $parsed_url['host'], $parsed_url['path'], $site_id ) ) {
			return false;
		}

		$blog_id = insert_blog( $parsed_url['host'], $parsed_url['path'], $site_id );

		if ( ! $blog_id ) {
			return false;
		}

		return $blog_id;
	}

	/**
	 * Replaces the db_prefix with a new one using sed
	 *
	 * @param $filename The filename of the sql file to which the db prefix should be replaced
	 * @param $old_db_prefix The db prefix to be replaced
	 * @param $new_db_prefix The new db prefix
	 */
	private function replace_db_prefix( $filename, $old_db_prefix, $new_db_prefix ) {
		$new_prefix = $new_db_prefix;

		if ( ! empty( $new_prefix ) ) {
			$mysql_chunks_regex = array(
				'DROP TABLE IF EXISTS',
				'CREATE TABLE',
				'LOCK TABLES',
				'INSERT INTO',
				'CREATE TABLE IF NOT EXISTS',
				'ALTER TABLE',
			);

			//build sed expressions
			$sed_commands = array();
			foreach( $mysql_chunks_regex as $regex ) {
				$sed_commands[] = "s/{$regex} `{$old_db_prefix}/{$regex} `{$new_prefix}/g";
			}

			foreach( $sed_commands as $sed_command ) {
				$full_command = "sed '$sed_command' -i $filename";
				$sed_result = \WP_CLI::launch( $full_command, false, false );

				if ( 0 !== $sed_result ) {
					\WP_CLI::warning( __( 'Something went wrong while running sed', 'mu-migration' ) );
				}
			}
		}
	}

	/**
	 * Checks whether sed is available or not
	 *
	 * @param bool $exit_on_error If set to true the script will be terminated if sed is not available
	 * @return bool True if sed is available, false otherwise
	 */
	private function check_for_sed_presence( $exit_on_error = false ) {
		$sed = \WP_CLI::launch( 'sed --version', false, false );

		if ( 0 !== $sed ) {
			if ( $exit_on_error ) {
				\WP_CLI::error( __( 'sed not present, please install sed', 'mu-migration' ) );
			}

			return false;
		}

		return true;
	}

}

WP_CLI::add_command( 'mu-migration import', __NAMESPACE__ . '\\ImportCommand' );
