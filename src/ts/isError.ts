export function isError(
	data:
		| GalleryResponse
		| ListGalleryDirResponse
		| ListGalleryFoldersResponse
		| ListGdriveDirResponse
		| PageResponse
): data is ErrorResponse {
	return 'error' in data;
}
