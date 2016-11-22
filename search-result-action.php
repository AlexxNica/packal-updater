<?php

require_once( __DIR__ . '/autoloader.php' );
use CFPropertyList\CFPropertyList as CFPropertyList;
use Alphred\Ini as Ini;

class Action {

	public function __construct( $args ) {
		// Decode the args sent over from the script filter
		$args = json_decode( $args, true );
		// Setup common variables for use
		$this->alphred  = new Alphred;
		$this->packal   = new Packal( ENVIRONMENT );
		$this->workflow = new Workflows( ENVIRONMENT );
		$this->theme    = new Themes( ENVIRONMENT );

		foreach ( [ 'action', 'resource', 'target', 'type', 'value' ] as $var ) :
			$parsed_args[ $var ] = ( isset( $args[ $var ] ) ) ? $args[ $var ] : false;
		endforeach;

		print_r( $this->do_action( $parsed_args ) );
	}

	public function do_action( $args ) {
		switch ( $args['action'] ) :

			case 'clear_caches':
				return $this->clear_caches();
				break;

			case 'configure':
				$this->configure( $args['target'], $args['value'] );
				$this->success = true;
				return "Username `{$args['value']}` saved.";
				break;

			case 'download':
				if ( $result = FileSystem::download_file( $args['target'], "{$_SERVER['HOME']}/Downloads/" ) ) {
					$this->success = true;
					return 'Download succesful.';
				} else {
					$this->success = false;
					return "Could not download `{$args['target']}`";
				}
				break;

			case 'generate_ini':
				$this->generate_ini( $args['target'] );
				$this->success = true;
				return 'Generated `workflow.ini`';
				break;

			case 'install':
				if ( 'workflow' === $args['type'] ) {
					$result = $this->workflow->install( $args['resource'] );
					return $this->check( $result, "Installed `{$args['resource']['name']}`." );
				} elseif ( 'theme' === $args['type'] ) {
					$result = $this->theme->install( Themes::find_slug( $args['resource']['url'] ) );
					return $this->check( $result, "Installed `{$args['resource']['name']}`." );
				}
				break;

			case 'migrate':
				return $this->migrate_workflow( $args['resource'] );
				break;

			case 'migrate-all-workflows':
				return $this->migrate_all_workflows( $args['resource'] );
				break;

			case 'open':
				exec( "open '{$args['target']}'" );
				return true;
				break;

			case 'report':
				if ( false === $this->report() ) {
					$this->messages['subtitle'] = $this->workflow['name'];
					$this->messages['text'] = 'Canceled sending report';
				} else {
					$result = json_decode( $result, true );
					$this->messages['subtitle'] = 'Report `' . $this->workflow['name'] . '`';
					$this->messages['messages'] = [ $result[0]['message'] ];
				}
				break;

			case 'submit':
				if ( 'workflow' === $args['type'] ) {
					$result = $this->submit_workflow( $args['resource']['bundle'] );
				} elseif ( 'theme' === $args['type'] ) {
					$result = $this->submit_theme( $args['resource'] );
				}
				$this->clear_caches();
				return $result;
				break;

			case 'update':
				return $this->update_workflow( $args['resource'] );
				break;

			case 'update-all-workflows':
				return $this->update_all_workflows( $args['resource'] );
				break;

		endswitch;
		return 'No action found.';
	}

	public function check( $result, $message ) {
		if ( true === $result ) {
			$this->success = true;
			return $message;
		} else {
			$this->success = false;
			return $result;
		}
	}

	private function update_workflow( $update, $verify_signature = true ) {
		if ( true === $result = $this->workflow->upgrade( $update, $verify_signature ) ) {
			$this->alphred->send_notification([
				'title'    => 'Packal Updater',
				'subtitle' => 'Update successful',
				'text'     => "Updated `{$update['new']['name']}`",
			]);
		} else {
			$this->alphred->send_notification([
				'title'    => 'Packal Updater',
				'subtitle' => "Error: failed to update {$update['new']['name']}`",
				'text'     => $result,
			]);
		}
		return $result;
	}

	public function update_all_workflows( $updates, $verify_signature = true ) {
		foreach ( $updates as $update ) :
			$this->update_workflow( $update, $verify_signature );
		endforeach;
		return true;
	}

	public function migrate_workflow( $workflow ) {
		$new = Workflows::find_workflow_by_bundle_from_packal( $workflow['bundle'] );
		return $this->update_workflow( [ 'old' => $workflow, 'new' => $new ], false );
	}

	public function migrate_all_workflows( $workflows ) {
		foreach ( $workflows as $workflow ) :
			$this->migrate_workflow( $workflow );
		endforeach;
		return true;
	}

	private function clear_caches( $bin = false ) {
		$request = new \Alphred\Request( BASE_URL );
		$request->clear_cache( PRIMARY_CACHE_BIN );
		return 'Cleared caches in ' . PRIMARY_CACHE_BIN;
	}

	private function download( $url, $directory = false ) {
		$directory = $directory ?: "{$_SERVER['HOME']}/Downloads/";
		if ( false !== $result = FileSystem::download_file( $url, $directory ) ) {
			return $result;
		}
		$this->log( "Could not download file ({$url}) to ({$directory})." );
		$this->alphred->send_notification([
			'title'    => 'Packal',
			'subtitle' => 'Error',
			'text'     => "Could not download {$url}",
		]);
		return false;
	}

	private function configure( $key, $value = false ) {
		if ( 'password' === $key ) {
			$password = $this->alphred->get_password_dialog(
				'Please enter your Packal.org password. If you do not have one, then please make an account on Packal.org and then set this.',
				'Packal Workflow'
			);
			if ( 'canceled' !== $password ) {
				$this->alphred->save_password( 'packal.org', $password );
				$this->alphred->console( 'Set Packal.org password in the keychain', 1 );
				$this->messages['messages'] = [ 'Successfully set Packal.org password.' ];
			} else {
				$this->alphred->console( 'Canceled `set password` operation.', 1 );
			}
			return;
		}
		$this->alphred->config_set( $key, $value );
	}

	private function log( $message, $status ) {
		$this->messages['status'] 		= ( ( $status ) ? 'Sucess' : 'Fail' );
		$this->messages['messages'][] = $message;
	}

	private function generate_ini( $path ) {
		$return = generate_ini( $path );
		$this->alphred->console( print_r( $workflow, true ), 4 );
		if ( $return[0] ) {
			$this->messages['subtitle'] = [ 'Workflow Generation Success' ];
			$this->messages['messages'] = [ 'Generated `workflow.ini` for ' . $return[1] ];
		} else {
			$this->messages['subtitle'] = [ 'Workflow Generation Failure' ];
			$this->messages['messages'] = [ 'Canceled `workflow.ini` for ' . $return[1] ];
		}
	}

	private function report( $resource ) {
		$values = [
			'workflow_name' => $resource['name'],
			'version'       => $resource['version'],
			'revision'      => $resource['revision_id'],
			'workflow'      => $resource['id'],
		];
		if ( ! $parsed = Pashua::go( 'pashau-report-config.ini', $values ) ) {
			return false;
		}
		$params = [
			'workflow_revision_id' => $parsed['revision'],
			'report_type'          => $parsed['type'],
			'message'              => $parsed['message'],
		];
		$output = submit_report( $params );
		return $output;
	}

	private function submit_theme( $theme ) {
		$metadata = $this->submit_build_theme_info( $theme );
		$uri = $theme['uri'];

		submit_theme([
			'uri'         => $theme['uri'],
			'description' => $metadata['theme_description'],
			'tags'        => $metadata['theme_tags'],
			'name'        => $theme['name'],
		]);
	}

	private function submit_workflow( $bundle ) {

		$workflow_path = Workflows::find_workflow_path_by_bundle( $bundle );

		if ( ! $username = $this->alphred->config_read( 'username' ) ) {
			$this->alphred->console( 'Could not read username in the config file.', 4 );
			$dialog = new \Alphred\Dialog([
				'title'  => 'Packal Error: No Username Set',
				'text'   => 'Please set your username with the `config` option to submit a workflow',
				'button' => 'Okay',
				'icon'   => 'stop',
			]);
			$dialog->execute();
			exit( 1 );
		}
		if ( ! $password = $this->alphred->get_password( 'packal.org' ) ) {
			$dialog = new \Alphred\Dialog([
				'title' => 'Packal Error: No Password Set',
				'text'  => 'Please set your password with the `config` option to submit a workflow',
				'icon'  => 'stop',
			]);
			$dialog->execute();
			exit( 1 );
		}
		$ini = Ini::read_ini( "{$workflow_path}/workflow.ini" );
		$version = $ini['workflow']['version'];

		if ( isset( $ini['packal']['screenshots_directory'] ) ) {
			if ( file_exists( $ini['packal']['screenshots_directory'] ) ) {
				$screenshots = $ini['packal']['screenshots_directory'];
			} else {
				$this->alphred->log( "Screenshots directory set in `workflow.ini` ({$ini['packal']['screenshots_directory']}) does not exist.", 3 );
				$screenshots = false;
			}
		} else {
			$screenshots = false;
		}

		if ( isset( $ini['packal']['description_file'] ) ) {
			if ( file_exists( $ini['packal']['description_file'] ) ) {
				$description_file = $ini['packal']['description_file'];
			} else {
				$this->alphred->log( "Description file set in `workflow.ini` ({$ini['packal']['description_file']}) does not exist.", 3 );
				$description_file = false;
			}
		} else {
			$description_file = false;
		}

		$workflow = new BuildWorkflow( $workflow_path, $screenshots, $description_file );
		// Let's actually do some submitting here
		$json = json_encode( [
			'file'     => $workflow->archive_name(),
			'username' => $username,
			'password' => $password,
			'version'  => $version,
		]);
		$output = submit_workflow( [ 'file' => $workflow->archive_name(), 'version' => $version ] );
		$this->alphred->console( print_r( $output, true ), 4 );
	}

	private function submit_build_theme_info( $theme ) {
		$dir = __DATA__ . '/data/themes';
		if ( ! file_exists( __DATA__ . '/data/themes' ) ) {
			mkdir( __DATA__ . '/data/themes', 0775, true );
		}
		$file = __DATA__ . '/data/themes/submit-' . $this->slugify( $theme['name'] ) . '.json';
		if ( file_exists( $file ) ) {
			$data = json_decode( file_get_contents( $file ), true );
		} else {
			$data = [
				'theme_description' => '',
				'theme_tags'        => [],
			];
		}
		$data['theme_name'] = $theme['name'];
		$metadata = $this->create_build_theme_info_dialog( $data );
		if ( $metadata ) {
			unset( $metadata['cb'] );
			$this->alphred->console( "Saving theme information for `{$theme['name']}`.", 1 );
			file_put_contents( $file, json_encode( $metadata, JSON_PRETTY_PRINT ) );
		} else {
			$this->alphred->console( 'User canceled saving theme information.', 1 );
			// Since it was canceled, let's just exit.
			exit( 1 );
		}
		return $metadata;
	}

	private function create_build_theme_info_dialog( $data ) {
		$data['theme_tags'] = implode( '[return]', $data['theme_tags'] );
		if ( ! $parsed = Pashua::go( 'pashau-theme-config.ini', $data ) ) {
			return false;
		}
		$parsed['theme_tags'] = explode( '[return]', $parsed['theme_tags'] );
		if ( empty( $parsed['theme_tags'][0] ) ) {
			$parsed['theme_tags'] = [];
		}
		$parsed['theme_description'] = str_replace( '[return]', "\n", $parsed['theme_description'] );
			return $parsed;
	}

	/**
	 * Makes a nice little slug, especially for use with files
	 *
	 * @param  [type] $slug [description]
	 * @return [type]       [description]
	 */
	private function slugify( $slug ) {
		$slug = strtolower( $slug );
		$slug = preg_replace( '/[^\w]{1,}/', '-', $slug );
		$slug = preg_replace( '/[-]{2,}/',   '-', $slug );
		if ( '-' === substr( $slug, -1 ) ) {
			$slug = substr( $slug, 0, -1 );
		}
		if ( '-' === substr( $slug, 0, 1 ) ) {
			$slug = substr( $slug, 1 );
		}
		return $slug;
	}

}

$action = new Action( $argv[1] );