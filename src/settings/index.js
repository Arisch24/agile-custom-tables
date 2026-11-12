/**
 * Settings screen: settings grouped in cards, saved through the plugin's
 * REST route. The PHP side prints the header and passes the current values
 * in window.agileCtSettings.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	ToggleControl,
} from '@wordpress/components';
import { createRoot, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import './index.css';

const { settings: initialSettings, path } = window.agileCtSettings;

function SettingsApp() {
	const [ saved, setSaved ] = useState( initialSettings );
	const [ draft, setDraft ] = useState( initialSettings );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ result, setResult ] = useState( null );

	const isDirty = Object.keys( draft ).some(
		( key ) => draft[ key ] !== saved[ key ]
	);

	const change = ( key ) => ( value ) => {
		setDraft( { ...draft, [ key ]: value } );
		setResult( null );
	};

	async function save( event ) {
		event.preventDefault();
		setIsSaving( true );
		setResult( null );
		try {
			const next = await apiFetch( {
				path,
				method: 'POST',
				data: draft,
			} );
			setSaved( next );
			setDraft( next );
			setResult( {
				status: 'success',
				message: __( 'Settings saved.', 'agile-custom-tables' ),
			} );
		} catch ( error ) {
			setResult( {
				status: 'error',
				message:
					error?.message ||
					__( 'Could not save settings.', 'agile-custom-tables' ),
			} );
		} finally {
			setIsSaving( false );
		}
	}

	return (
		<form className="agile-ct-settings__form" onSubmit={ save }>
			{ result && (
				<Notice
					status={ result.status }
					onRemove={ () => setResult( null ) }
				>
					{ result.message }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<h2 className="agile-ct-settings__card-title">
						{ __( 'Uninstall', 'agile-custom-tables' ) }
					</h2>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Delete all data when the plugin is deleted',
							'agile-custom-tables'
						) }
						help={ __(
							'Removes every content type, its database table and all entries when you delete the plugin from the Plugins screen. Deactivating never removes anything.',
							'agile-custom-tables'
						) }
						checked={ draft.delete_data_on_uninstall }
						onChange={ change( 'delete_data_on_uninstall' ) }
					/>
					{ draft.delete_data_on_uninstall && (
						<Notice
							status="warning"
							isDismissible={ false }
							className="agile-ct-settings__warning"
						>
							{ __(
								'Deleting the plugin will then permanently remove all content types and entries. This cannot be undone.',
								'agile-custom-tables'
							) }
						</Notice>
					) }
				</CardBody>
			</Card>

			<div className="agile-ct-settings__actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					type="submit"
					isBusy={ isSaving }
					disabled={ ! isDirty || isSaving }
				>
					{ isSaving
						? __( 'Saving…', 'agile-custom-tables' )
						: __( 'Save settings', 'agile-custom-tables' ) }
				</Button>
			</div>
		</form>
	);
}

const root = document.getElementById( 'agile-ct-settings-root' );
if ( root ) {
	createRoot( root ).render( <SettingsApp /> );
}
