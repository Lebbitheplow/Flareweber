export type Bindings = {
  ASSETS: Fetcher
  DB?: D1Database
  MEDIA?: R2Bucket
  SITE_URL: string
  SITE_NAME: string
  SITE_DOMAIN: string
  CURRENCY: string
  FEATURES: string
  CART_SECRET?: string
  STRIPE_SECRET_KEY?: string
  STRIPE_WEBHOOK_SECRET?: string
}

export type Env = { Bindings: Bindings }

export type ProductRow = {
  id: number
  slug: string
  title: string
  description: string | null
  price_cents: number
  currency: string
  sku: string | null
  image: string | null
  images_json: string | null
}

export type Category = { id: number; name: string; slug: string }

export type Variant = {
  id: number
  product_id: number
  sku: string | null
  title: string | null
  price_cents: number
  currency: string
  quantity: number
}

export type CartItem = Variant & {
  product_title: string
  slug: string
  image: string | null
  quantity_in_cart: number
  quantity_available: number
}
