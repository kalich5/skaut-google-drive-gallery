import type { BlockEditProps } from '@wordpress/blocks';

import * as blockEditor from '@wordpress/block-editor';
import * as editor from '@wordpress/editor';
import { Component, createElement, Fragment } from '@wordpress/element';
import $ from 'jquery';

import type { Attributes } from '../interfaces/Attributes';

import { isError } from '../../isError';
import { SgdgSettingsOverrideComponent } from './SgdgSettingsOverrideComponent';

interface SgdgEditorComponentState {
	error: string | undefined;
	list: Array<PartialDirectory> | undefined;
	path: Array<PartialDirectory>;
}

export class SgdgEditorComponent extends Component<
	BlockEditProps<Attributes>,
	SgdgEditorComponentState
> {
	public constructor(props: BlockEditProps<Attributes>) {
		super(props);
		this.state = { error: undefined, list: undefined, path: [] };
	}

	public override componentDidMount(): void {
		this.ajax();
	}

	public getAttribute(
		name: string
	): Array<string> | number | string | undefined {
		const { attributes } = this.props;
		return attributes[name];
	}

	public override render(): React.ReactNode {
		const { error, list, path } = this.state;
		const InspectorControls =
			// eslint-disable-next-line @typescript-eslint/no-deprecated, @typescript-eslint/no-unnecessary-condition -- In older versions of Gutenberg, InspectorControls was on editor
			blockEditor.InspectorControls ?? editor.InspectorControls;
		if (error !== undefined) {
			return createElement(
				'div',
				{ class: 'notice notice-error' },
				createElement('p', null, error)
			);
		}
		const children = [];
		const pathElements: Array<React.ReactNode> = [
			createElement(
				'a',
				{
					onClick: () => {
						this.navigate([]);
					},
				},
				sgdgBlockLocalize.root_name
			),
		];
		if (list) {
			if (0 < path.length) {
				children.push(
					createElement(
						'tr',
						null,
						createElement(
							'td',
							{ class: 'row-title' },
							createElement(
								'label',
								{
									onClick: () => {
										this.navigate(
											path.slice(0, path.length - 1)
										);
									},
								},
								'..'
							)
						)
					)
				);
			}
			for (let i = 0; i < list.length; i++) {
				const folder = list[i];
				const lineClass =
					(0 === path.length && 1 === i % 2) ||
					(0 < path.length && 0 === i % 2)
						? 'alternate'
						: '';
				children.push(
					createElement(
						'tr',
						{ class: lineClass },
						createElement(
							'td',
							{ class: 'row-title' },
							createElement(
								'label',
								{
									onClick: () => {
										this.navigate(path.concat(folder));
									},
								},
								folder.name
							)
						)
					)
				);
			}
			for (let i = 0; i < path.length; i++) {
				pathElements.push(' > ');
				pathElements.push(
					createElement(
						'a',
						{
							onClick: () => {
								this.navigate(path.slice(0, i + 1));
							},
						},
						path[i].name
					)
				);
			}
		}
		return createElement(Fragment, null, [
			createElement(
				InspectorControls,
				null,
				createElement(SgdgSettingsOverrideComponent, { editor: this })
			),
			createElement('table', { class: 'widefat' }, [
				createElement(
					'thead',
					null,
					createElement(
						'tr',
						null,
						createElement(
							'th',
							{ class: 'sgdg-block-editor-path' },
							pathElements
						)
					)
				),
				createElement('tbody', null, children),
				createElement(
					'tfoot',
					null,
					createElement(
						'tr',
						null,
						createElement(
							'th',
							{ class: 'sgdg-block-editor-path' },
							pathElements
						)
					)
				),
			]),
		]);
	}

	public setAttribute(
		name: string,
		value: Array<string> | number | string | undefined
	): void {
		const { setAttributes } = this.props;
		const attr: Attributes = {};
		attr[name] = value;
		setAttributes(attr);
	}

	private ajax(): void {
		const pathIds = this.getAttribute('path_ids') as
			Array<string> | undefined;
		void $.get(
			sgdgBlockLocalize.ajax_url,
			{
				_ajax_nonce: sgdgBlockLocalize.nonce,
				action: 'list_gallery_folders',
				// Blocks created before folder IDs were stored only have the folder names.
				...(pathIds === undefined
					? { path: this.getAttribute('path') }
					: { path_ids: pathIds }),
			},
			(data: ListGalleryFoldersResponse) => {
				if (isError(data)) {
					this.setState({ error: data.error });
				} else {
					this.setState({ list: data.directories, path: data.path });
					this.storePath(data.path);
				}
			}
		);
	}

	private navigate(path: Array<PartialDirectory>): void {
		this.storePath(path);
		this.setState({ error: undefined, list: undefined, path }, () => {
			this.ajax();
		});
	}

	/*
	 * Stores both the IDs and the names of the folders. The IDs are used to find the gallery, so renaming a folder doesn't break it. The names are kept up to date as a fallback and for older versions of the plugin.
	 */
	private storePath(path: Array<PartialDirectory>): void {
		const { setAttributes } = this.props;
		const ids = path.map((folder) => folder.id);
		const names = path.map((folder) => folder.name);
		if (
			JSON.stringify(ids) ===
				JSON.stringify(this.getAttribute('path_ids')) &&
			JSON.stringify(names) === JSON.stringify(this.getAttribute('path'))
		) {
			return;
		}
		setAttributes({ path: names, path_ids: ids });
	}
}
