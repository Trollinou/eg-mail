export {};

declare global {
	interface Window {
		readonly ajaxurl?: string;
		// ... vos autres objets typés en snake_case
	}
}