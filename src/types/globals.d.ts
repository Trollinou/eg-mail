export {};

declare global {
	interface Window {
		readonly ajaxurl?: string;
		readonly eg_mail_admin_data?: {
			readonly api_url: string;
			readonly nonce: string;
			readonly night_start: string;
			readonly now_local: string;
			readonly i18n: {
				readonly confirm_cancel: string;
				readonly confirm_purge: string;
				readonly loading: string;
				readonly error: string;
			};
		};
	}
}
