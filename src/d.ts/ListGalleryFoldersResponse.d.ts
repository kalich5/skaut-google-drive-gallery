declare interface ListGalleryFoldersSuccessResponse {
	directories: Array<PartialDirectory>;
	path: Array<PartialDirectory>;
}

type ListGalleryFoldersResponse =
	ErrorResponse | ListGalleryFoldersSuccessResponse;
