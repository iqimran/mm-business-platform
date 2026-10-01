/** Standard API response contract (docs/04-api.md). */
export type ApiSuccess<T> = {
  success: true;
  message: string;
  data: T;
};

export type Paginated<T> = {
  items: T[];
  pagination: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
};

export type ApiFailure = {
  success: false;
  message: string;
  errors?: Record<string, string[]>;
};
