export type Env = {
  Bindings: {
    ASSETS: Fetcher
    DB?: D1Database
    MEDIA?: R2Bucket
    SITE_ID: string
    SITE_DOMAIN: string
    CART_SECRET?: string
    STRIPE_SECRET_KEY?: string
    STRIPE_WEBHOOK_SECRET?: string
  }
}

export type Product = {
  id: number
  slug: string
  title: string
  description: string | null
  image: string | null
  published: number
}

export type Variant = {
  id: number
  product_id: number
  sku: string | null
  title: string | null
  price_cents: number
  currency: string
  quantity?: number
}

export type CartItem = Variant & { quantity_in_cart: number }
