# Existing settings layouts

The product translation panel is a new embedded editor target, not a replacement settings shell. The following full sources document the existing plugin shell.

## admin/settings/views/src/components/main-component.jsx

```jsx
//This page is the main ruter from where which tab->Components is mapped
import React from 'react'
import General from './general'
import Sidebar from './sidebar'
import apiFetch from "@wordpress/api-fetch"
import { getNonce } from '../utils'
import { LoaderPinwheel } from "lucide-react"
import { Loader } from "@bsf/force-ui"
import { sprintf,__ } from '@wordpress/i18n'
import TranslationConfig from './translation-config'
import Switcher from './switcher'
import Migration from './migration'
//Component mapper for settings page
const ComponentSelector = ({currentPage,data, setData})=>{
  if(currentPage === 'general') return <General data={data} setData={setData} />
  if(currentPage === 'translation') return <TranslationConfig data={data} setData={setData} />
  if(currentPage === 'switcher') return <Switcher data={data} setData={setData} />
  if(currentPage === 'advanced-settings') return <Migration data={data} setData={setData} />
}


const MainComponent = ({ currentPage }) => {
  const [data, setData] = React.useState({}) //General Settings data
  const [loading, setLoading] = React.useState(true) //Loading state tracker
  React.useEffect(() => {
    async function serverCall() {
      //API call for getting general settings           
      const responseData = await apiFetch({
        path: 'lmat/v1/settings',
        method: 'GET',
        'headers': {
          'Content-Type': 'application/json',
          'X-WP-Nonce': getNonce()
        }
      })
      setData(responseData)
      setLoading(false)



    }
    serverCall()
  }, [])

  return (
    <div className='lg:flex gap-8 px-8 mt-8'>
      <div className='w-full lg:w-[75%]'>
        {
          loading ?
            <div className='flex justify-center gap-4 items-center min-h-[100vh]'>
              <h1>
                <Loader
                  className=""
                  icon={<LoaderPinwheel className="animate-spin" />}
                  size="md"
                  variant="primary"
                /></h1> <h1 className='m-0'>{__("Loading", "translate-words")}</h1>
            </div> :
            <>
              <ComponentSelector currentPage={currentPage} data={data} setData={setData} />
            </>
        }

      </div>
      <div className='w-full lg:w-[25%] mt-8 lg:mt-0'>
        <Sidebar />
      </div>
    </div>
  )
}

export default MainComponent
```

## admin/settings/views/src/components/sidebar.jsx

```jsx
import { Container, Button } from "@bsf/force-ui"
import { __ } from '@wordpress/i18n';

const Sidebar = () => {
  // Get LocoAI plugin status from localized script data
  const locoaiStatus = window.lmat_settings?.locoai_plugin_status || { status: 'not_installed' };

  return (
    <>
      <div className='w-full'>
        <div className='w-full flex flex-col gap-8 rounded-lg'>
          <Container className='flex flex-col p-6  bg-white border border-gray-200 rounded-lg shadow-sm'>
            <Container.Item>
              <h2 className='text-lg font-semibold text-gray-900 mb-2'>{__('Auto Translation Status', 'translate-words')}</h2>
              <Container.Item className=''>
                <h1 className='text-3xl font-bold text-gray-900 m-0'>{window.lmat_settings?.translations_data?.total_character || 0}</h1>
                <p className='text-sm text-gray-600 m-0'>{__('Total Characters Translated!', 'translate-words')}</p>
              </Container.Item>
            </Container.Item>
            <hr className="w-full border-b-0 border-x-0 border-t border-solid border-t-border-subtle my-1" />
            <Container.Item className='w-full'>
              <Container.Item className='flex flex-col gap-1'>
                <div className='flex justify-between items-center'>
                  <h4 className='text-sm text-gray-700 m-0'>{__('Total Strings', 'translate-words')}</h4>
                  <p className='text-sm font-medium text-gray-900 m-0'>{window.lmat_settings?.translations_data?.total_string || 0}</p>
                </div>
                <div className='flex justify-between items-center'>
                  <h4 className='text-sm text-gray-700 m-0'>{__('Total Pages / Posts', 'translate-words')}</h4>
                  <p className='text-sm font-medium text-gray-900 m-0'>{window.lmat_settings?.translations_data?.total_pages || 0}</p>
                </div>
                <div className='flex justify-between items-center'>
                  <h4 className='text-sm text-gray-700 m-0'>{__('Time Taken', 'translate-words')}</h4>
                  <p className='text-sm font-medium text-gray-900 m-0'>{window.lmat_settings?.translations_data?.total_time || 0}</p>
                </div>
                <div className='flex flex-col gap-2'>
                  <div className='flex flex-col gap-1'>
                    <h4 className='text-sm text-gray-700 m-0 text-nowrap'>{__('Service Providers', 'translate-words')}</h4>
                  </div>
                  <div className='flex flex-wrap gap-2'>
                  {window.lmat_settings?.translations_data?.service_providers?.map((provider, index) => (
                    <span className='text-sm font-medium text-gray-900 m-0 bg-gray-200 px-2 py-1 rounded-md' key={index}>{provider}</span>
                  ))}
                  </div>
                </div>
              </Container.Item>
            </Container.Item>
          </Container>
          {
            locoaiStatus.status === 'not_installed' ? (

              <div className=' p-6 bg-white border border-gray-200 rounded-lg shadow-sm'>
                <h2>{__('Automatically Translate Plugins & Themes', 'translate-words')}</h2>
                <hr className="w-full border-b-0 border-x-0 border-t border-solid border-t-border-subtle my-1" />
                <Container.Item className='flex'>
                  <div className='w-[70%]'>
                    <h4>{__('LocoAI - Auto Translation for Loco Translate', 'translate-words')}</h4>
                    <a target="_blank" href="plugin-install.php?s=locoai&tab=search&type=term">
                    <Button
                      className=""
                      iconPosition="left"
                      size="md"
                      tag="button"
                      type="button"
                      variant="primary"
                    >
                      {__('Install', 'translate-words')}
                    </Button>
                    </a>
                  </div>
                  <div className='w-[30%] flex items-center object-contain p-2'>
                    <a href='plugin-install.php?s=locoai&tab=search&type=term' target="_blank"><img className="w-auto max-h-24  " src={`${window.lmat_settings_logo_data.logoUrl}loco.png`} alt="Loco translate logo" /></a>
                  </div>
                  <div></div>
                </Container.Item>
              </div>

            ) : null
          }
          <Container className='bg-white flex flex-col gap-4 p-6 shadow-sm rounded-lg'>
            <div>
              <h2><a className="no-underline text-black" target="_blank" href="https://wordpress.org/support/plugin/translate-words/reviews/#new-post">{__('Rate Us ⭐⭐⭐⭐⭐', 'translate-words')}</a></h2>
              <p>{__("We'd love your feedback! Hope this addon made auto-translations easier for you.", 'translate-words')}</p>
              <a target="_blank" href="https://wordpress.org/support/plugin/translate-words/reviews/#new-post">{__('Submit a Review →', 'translate-words')}</a>
            </div>
          </Container>
        </div>

      </div>
    </>
  )
}

export default Sidebar
```

## admin/settings/header/header.php

```php
<?php

namespace Linguator\Settings\Header;

use Linguator\Includes\Migration\Polylang_Migration;
use Linguator\Includes\Migration\WPML_Migration;
/**
 * Header file for settings page
 *
 * @package Linguator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Linguator\Settings\Header\Header' ) ) {
    /**
     * Header class
     * @param mixed $tab
     */
	class Header {

		/**
		 * Instance of the class
		 * @var mixed
		 */
		private static $instance;

		/**
		 * Active tab
		 * @var mixed
		 */
		private $active_tab;

        /**
         * Model
         * @var mixed
         */
        private $model;

		/**
		 * Get instance of the class
		 * @param mixed $tab
		 * @param mixed $model
		 * @return mixed
		 */
		public static function get_instance( $tab, $model ) {
			if ( null === self::$instance ) {
				self::$instance = new self( $tab, $model );
			}

			return self::$instance;
		}

		/**
		 * Constructor
		 * @param mixed $tab
		 * @param mixed $model
		 */
		public function __construct( $tab, $model ) {
			$this->active_tab = sanitize_text_field( $tab );
			$this->model = $model;
		}

		/**
		 * True when Polylang or WPML left migratable data in the database (same rules as migration detect endpoints).
		 *
		 * @return bool
		 */
		private function has_migration_source_data() {
			try {
				$polylang = new Polylang_Migration( $this->model, $this->model->options );
				if ( false !== $polylang->detect_polylang() ) {
					return true;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}try {
				$wpml = new WPML_Migration( $this->model, $this->model->options );
				if ( false !== $wpml->detect_wpml() ) {
					return true;
				}} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
			return false;
		}

		/**
		 * Tabs
		 * @return mixed
		 */
		public function tabs() {
			$default_url = '';

			if ( $this->active_tab && in_array($this->active_tab, ['strings', 'lang', 'supported-blocks','custom-fields','glossary']) ) {
				$default_url = 'lmat_settings';
			}

		$tabs = array(
			'general'     => array( 'title' => __( 'General Settings', 'translate-words' ) ),
			'lang'   => array( 'title' => __( 'Manage Languages', 'translate-words' ), 'redirect' => true, 'redirect_url' => 'lmat' ),
			'translation' => array( 'title' => __( 'AI Translation', 'translate-words' ) ),
			'switcher'    => array( 'title' => __( 'Language Switcher', 'translate-words' ) ),
			'supported-blocks' => array( 'title' => __( 'Supported Blocks', 'translate-words' ), 'redirect' => true, 'redirect_url' => 'lmat_settings&tab=supported-blocks' ),
			'custom-fields' => array( 'title' => __( 'Custom Fields', 'translate-words' ), 'redirect' => true, 'redirect_url' => 'lmat_settings&tab=custom-fields' ),
		);

		// Only show Advanced Settings tab if migration hasn't been completed AND Polylang data exists
		$migration_completed = get_option( 'lmat_migration_completed', false );
		if ( ! $migration_completed && $this->has_migration_source_data() ) {
			$tabs['advanced-settings'] = array( 'title' => __( 'Advanced Settings', 'translate-words' ) );
		}

        $languages = $this->model->get_languages_list();
        
        // Only show Glossary tab if languages exist
        if(!empty($languages)){
            $tabs['glossary'] = array( 'title' => __( 'Glossary', 'translate-words' ), 'redirect' => true, 'redirect_url' => 'lmat_settings&tab=glossary' );
        }
        
       
            $tabs['strings']     = array(
				'title'        => __( 'Static Strings', 'translate-words' ),
				'redirect'     => true,
				'redirect_url' => 'lmat_settings&tab=strings',
			);
        

			if ( $default_url && ! empty( $default_url ) ) {
				$tabs['general']['redirect']         = true;
				$tabs['general']['redirect_url']     = $default_url . '&tab=general';
				$tabs['translation']['redirect']     = true;
				$tabs['translation']['redirect_url'] = $default_url . '&tab=translation';

				$tabs['switcher']['redirect']     = true;
				$tabs['switcher']['redirect_url'] = $default_url . '&tab=switcher';
				
				// Only set redirect for advanced-settings if the tab exists
				if ( isset( $tabs['advanced-settings'] ) ) {
					$tabs['advanced-settings']['redirect']     = true;
					$tabs['advanced-settings']['redirect_url'] = $default_url . '&tab=advanced-settings';
				}
			}

			return apply_filters( 'lmat_settings_header_tabs', $tabs );
		}

		/**
		 * @return void
		 */
		public function header() {
			echo '<div id="lmat-settings-header">';
			echo '<div id="lmat-settings-header-tabs">';
			echo '<div class="lmat-settings-header-tab-container">';
			echo '<div class="lmat-settings-header-logo">';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=lmat_settings&tab=general' ) ) . '"><img src="' . esc_url( plugin_dir_url( LINGUATOR_ROOT_FILE ) . 'assets/logo/linguator_icon.svg' ) . '" alt="Linguator" /></a>';
			echo '</div>';
			echo '<div class="lmat-settings-header-tab-list">';
			foreach ( $this->tabs() as $key => $value ) {
				$active_class = $this->active_tab === $key ? 'active' : '';
				$title        = $value['title'];
				$redirect     = isset( $value['redirect'] ) ? $value['redirect'] : false;
				$redirect_url = $redirect && isset( $value['redirect_url'] ) ? $value['redirect_url'] : false;
				if ( $redirect && $redirect_url && $this->active_tab !== $key ) {
					echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . esc_attr( $redirect_url ) ) ) . '"><div class="lmat-settings-header-tab ' . esc_attr( $active_class ) . '" data-tab="' . esc_attr( $key ) . '" title="' . esc_attr( $title ) . '" data-link="true">' . esc_html(  $title  ) . '</div></a>';
				} else {
					echo '<div class="lmat-settings-header-tab ' . esc_attr( $active_class ) . '" data-tab="' . esc_attr( $key ) . '" title="' . esc_attr( $title ) . '">' . esc_html(  $title  ) . '</div>';
				}
			}
			echo '</div>';
			echo '<div class="lmat-settings-header-actions">';
			$docs_label    = esc_html__( 'Documentation', 'translate-words' );
			$video_label   = esc_html__( 'Video Tutorial', 'translate-words' );
			$support_label = esc_html__( 'Support', 'translate-words' );

			$docs_icon_url    = plugin_dir_url( LINGUATOR_ROOT_FILE ) . 'assets/logo/docs.svg';
			$video_icon_url   = plugin_dir_url( LINGUATOR_ROOT_FILE ) . 'assets/logo/video.svg';
			$support_icon_url = plugin_dir_url( LINGUATOR_ROOT_FILE ) . 'assets/logo/support.svg';

			echo '<a href="https://linguator.com/documentation/?utm_source=twlmat_plugin&utm_medium=inside&utm_campaign=docs&utm_content=dashboard" target="_blank" rel="noopener noreferrer" class="lmat-header-action-link" title="' . esc_attr( $docs_label ) . '"><span class="lmat-header-action-icon" style="--lmat-icon-url: url(\'' . esc_url( $docs_icon_url ) . '\');" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $docs_label ) . '</span></a>';
			echo '<a href="https://linguator.com/docs/video-tutorials/?utm_source=twlmat_plugin&utm_medium=inside&utm_campaign=video&utm_content=dashboard" target="_blank" rel="noopener noreferrer" class="lmat-header-action-link" title="' . esc_attr( $video_label ) . '"><span class="lmat-header-action-icon" style="--lmat-icon-url: url(\'' . esc_url( $video_icon_url ) . '\');" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $video_label ) . '</span></a>';
			echo '<a href="https://my.coolplugins.net/account/support-tickets/?utm_source=twlmat_plugin&utm_medium=inside&utm_campaign=support&utm_content=dashboard" target="_blank" rel="noopener noreferrer" class="lmat-header-action-link" title="' . esc_attr( $support_label ) . '"><span class="lmat-header-action-icon" style="--lmat-icon-url: url(\'' . esc_url( $support_icon_url ) . '\');" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $support_label ) . '</span></a>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
			echo '<script>
				(function() {
					var header = document.getElementById("lmat-settings-header");
					var wpbodyContent = document.getElementById("wpbody-content");
					if (header && wpbodyContent) {
						wpbodyContent.insertBefore(header, wpbodyContent.firstChild);
					}
				})();
			</script>';
		}

		/**
		 * @return void
		 */
		public function header_assets() {
			wp_enqueue_style( 'lmat-settings-header', plugins_url( 'admin/assets/css/settings-header.css', LINGUATOR_ROOT_FILE ), array(), LINGUATOR_VERSION );
		}
	}

}

```
