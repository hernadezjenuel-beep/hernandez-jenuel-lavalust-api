import React, { useEffect, useState } from 'react';

const API_BASE_URL = 'https://hernandez-jenuel-lavalust-api.onrender.com';
const SESSION_KEY = 'lavalust-account-session';

async function request(path, { token, ...options } = {}) {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });

  const text = await response.text();
  let data = {};
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error('The server returned an unexpected response. Check that the API is running and configured for JSON.');
    }
  }

  if (!response.ok) {
    throw new Error(data.error || data.message || `Request failed (${response.status}).`);
  }

  return data;
}

function getSavedSession() {
  try {
    const saved = sessionStorage.getItem(SESSION_KEY);
    return saved ? JSON.parse(saved) : null;
  } catch {
    return null;
  }
}

function App() {
  const [session, setSession] = useState(getSavedSession);
  const [notice, setNotice] = useState(null);
  const [busy, setBusy] = useState(false);
  const [products, setProducts] = useState([]);
  const [productsLoading, setProductsLoading] = useState(false);
  const [productBusy, setProductBusy] = useState(false);
  const [editingProduct, setEditingProduct] = useState(null);

  useEffect(() => {
    if (session?.token && session?.user) {
      sessionStorage.setItem(SESSION_KEY, JSON.stringify(session));
    } else {
      sessionStorage.removeItem(SESSION_KEY);
    }
  }, [session]);

  useEffect(() => {
    if (!session?.token) {
      setProducts([]);
      setEditingProduct(null);
      return undefined;
    }

    let active = true;
    setProductsLoading(true);
    request('/api/products', { token: session.token })
      .then((result) => {
        if (active) setProducts(result.data);
      })
      .catch((error) => {
        if (!active) return;
        setNotice({ type: 'error', text: `Could not load products: ${error.message}` });
        if (error.message === 'Unauthorized') setSession(null);
      })
      .finally(() => {
        if (active) setProductsLoading(false);
      });

    return () => {
      active = false;
    };
  }, [session?.token]);

  async function authenticate(event) {
    event.preventDefault();
    const formElement = event.currentTarget;
    const form = new FormData(formElement);
    const values = Object.fromEntries(form.entries());
    setBusy(true);
    setNotice(null);

    try {
      const result = await request('/api/auth/login', {
        method: 'POST',
        body: JSON.stringify({ username: values.username, password: values.password }),
      });

      const nextSession = {
        token: result.tokens.access_token,
        user: result.user,
      };
      setSession(nextSession);
      setNotice({
        type: 'success',
        text: `Welcome back, ${result.user.username}.`,
      });
      formElement.reset();
    } catch (error) {
      setNotice({ type: 'error', text: error.message });
    } finally {
      setBusy(false);
    }
  }

  async function createAccount(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const values = Object.fromEntries(new FormData(form).entries());
    setBusy(true);
    setNotice(null);

    try {
      const result = await request('/api/users', {
        method: 'POST',
        token: session.token,
        body: JSON.stringify(values),
      });
      setNotice({
        type: 'success',
        text: `${result.data.username} was created as ${result.data.role}.`,
      });
      form.reset();
    } catch (error) {
      setNotice({ type: 'error', text: error.message });
      if (error.message === 'Unauthorized') {
        setSession(null);
      }
    } finally {
      setBusy(false);
    }
  }

  async function createProduct(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const values = Object.fromEntries(new FormData(form).entries());
    setProductBusy(true);
    setNotice(null);

    try {
      const result = await request('/api/products', {
        method: 'POST',
        token: session.token,
        body: JSON.stringify({
          ...values,
          description: values.description || null,
          price: values.price,
          stock: values.stock,
        }),
      });
      setProducts((current) => [result.data, ...current]);
      form.reset();
      setNotice({ type: 'success', text: `${result.data.name} was added to products.` });
    } catch (error) {
      setNotice({ type: 'error', text: error.message });
      if (error.message === 'Unauthorized') setSession(null);
    } finally {
      setProductBusy(false);
    }
  }

  async function updateProduct(event, id) {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(event.currentTarget).entries());
    setProductBusy(true);
    setNotice(null);

    try {
      const result = await request(`/api/products/${encodeURIComponent(id)}`, {
        method: 'PUT',
        token: session.token,
        body: JSON.stringify({
          ...values,
          description: values.description || null,
          price: values.price,
          stock: values.stock,
        }),
      });
      setProducts((current) => current.map((product) => (
        String(product.id) === String(id) ? result.data : product
      )));
      setEditingProduct(null);
      setNotice({ type: 'success', text: `${result.data.name} was updated.` });
    } catch (error) {
      setNotice({ type: 'error', text: error.message });
      if (error.message === 'Unauthorized') setSession(null);
    } finally {
      setProductBusy(false);
    }
  }

  async function deleteProduct(product) {
    if (!window.confirm(`Delete "${product.name}"? This cannot be undone.`)) return;

    setProductBusy(true);
    setNotice(null);
    try {
      await request(`/api/products/${encodeURIComponent(product.id)}`, {
        method: 'DELETE',
        token: session.token,
      });
      setProducts((current) => current.filter((item) => String(item.id) !== String(product.id)));
      setNotice({ type: 'success', text: `${product.name} was deleted.` });
    } catch (error) {
      setNotice({ type: 'error', text: error.message });
      if (error.message === 'Unauthorized') setSession(null);
    } finally {
      setProductBusy(false);
    }
  }

  function signOut() {
    setSession(null);
    setNotice({ type: 'success', text: 'You have been signed out.' });
  }

  return (
    <main className="page-shell">
      <header className="topbar">
        <a className="brand" href="/" aria-label="Lavalust account portal home">
          <span className="brand-mark">L</span>
          <span>LAVALUST<span className="brand-light"> / PORTAL</span></span>
        </a>
        <span className="topbar-note"><span className="status-dot" /> ACCOUNT MANAGEMENT</span>
      </header>

      <section className="hero">
        <div className="hero-copy">
          <p className="eyebrow"><span>01</span> YOUR WORKSPACE, UNDER CONTROL</p>
          <h1>Good access<br />starts <em>here.</em></h1>
          <p className="hero-description">
            Sign in to manage your account. Administrators can manage who gets access next.
          </p>
          <div className="hero-meta">
            <span>SECURE ACCESS</span><span className="meta-separator">/</span>
            <span>ROLE-BASED CONTROL</span>
          </div>
        </div>

        <div className="form-panel">
          {session ? (
            <div className="signed-in">
              <div className="panel-heading">
                <div>
                  <p className="eyebrow"><span>SESSION ACTIVE</span></p>
                  <h2>You’re in.</h2>
                </div>
                <button className="sign-out-button" type="button" onClick={signOut}>Sign out</button>
              </div>

              <div className="identity-card">
                <div className="avatar">{session.user.username?.slice(0, 1).toUpperCase() || 'U'}</div>
                <div className="identity-copy">
                  <strong>{session.user.username}</strong>
                  <span>{session.user.email}</span>
                </div>
                <span className={`role-pill ${session.user.role === 'admin' ? 'role-admin' : ''}`}>
                  {session.user.role}
                </span>
              </div>

              {session.user.role === 'admin' ? (
                <form className="stack-form admin-form" onSubmit={createAccount}>
                  <div className="section-heading">
                    <div>
                      <p className="eyebrow"><span>ADMIN TOOLS</span></p>
                      <h3>Create an account</h3>
                    </div>
                    <span className="section-count">01 / 01</span>
                  </div>
                  <label>
                    Username
                    <input name="username" autoComplete="off" minLength="3" maxLength="100" pattern="[A-Za-z0-9_]+" placeholder="new_user" required />
                  </label>
                  <label>
                    Email address
                    <input name="email" type="email" maxLength="255" autoComplete="email" placeholder="name@example.com" required />
                  </label>
                  <div className="field-row">
                    <label>
                      Temporary password
                      <input name="password" type="password" minLength="8" maxLength="72" autoComplete="new-password" placeholder="At least 8 characters" required />
                    </label>
                    <label>
                      Account role
                      <select name="role" defaultValue="user">
                        <option value="user">User</option>
                        <option value="admin">Administrator</option>
                      </select>
                    </label>
                  </div>
                  <button className="primary-button" type="submit" disabled={busy}>
                    {busy ? 'Creating account…' : 'Create account'} <span>↗</span>
                  </button>
                  <p className="form-footnote">Passwords are securely hashed. Share credentials privately.</p>
                </form>
              ) : (
                <div className="member-note">
                  <span className="note-icon">↗</span>
                  <div>
                    <strong>Regular account</strong>
                    <p>Account creation tools are available to administrators only.</p>
                  </div>
                </div>
              )}
            </div>
          ) : (
            <>
              <div className="panel-heading">
                <div>
                  <p className="eyebrow"><span>WELCOME BACK</span></p>
                  <h2>Sign in.</h2>
                </div>
                <span className="panel-index">A—01</span>
              </div>

              <form className="stack-form" onSubmit={authenticate}>
                <label>
                  Username
                  <input
                    name="username"
                    type="text"
                    autoComplete="username"
                    maxLength="100"
                    placeholder="Enter your username"
                    required
                  />
                </label>
                <label>
                  Password
                  <input name="password" type="password" autoComplete="current-password" maxLength="72" placeholder="Enter your password" required />
                </label>
                <button className="primary-button" type="submit" disabled={busy}>
                  {busy ? 'Please wait…' : 'Sign in to portal'} <span>↗</span>
                </button>
              </form>

              <p className="form-footnote">
                Your access is protected by your personal credentials.
              </p>
            </>
          )}

          {notice && (
            <div className={`notice notice-${notice.type}`} role="status">
              <span>{notice.type === 'success' ? '✓' : '!'}</span>
              {notice.text}
            </div>
          )}
        </div>
      </section>

      {session && (
        <section className="products-section" aria-labelledby="products-title">
          <div className="products-heading">
            <div>
              <p className="eyebrow"><span>02</span> CATALOG MANAGEMENT</p>
              <h2 id="products-title">Products <span>{products.length.toString().padStart(2, '0')}</span></h2>
              <p className="products-intro">Create, update, and manage the products in your catalog.</p>
            </div>
            <span className="section-count">AUTHENTICATED · {session.user.role.toUpperCase()}</span>
          </div>

          <form className="product-create-form" onSubmit={createProduct}>
            <div className="product-form-title">
              <p className="eyebrow"><span>ADD TO CATALOG</span></p>
              <h3>New product</h3>
            </div>
            <label>
              Product name
              <input name="name" maxLength="150" placeholder="Product name" required />
            </label>
            <label>
              Description
              <textarea name="description" rows="2" placeholder="Short product description" />
            </label>
            <div className="product-form-numbers">
              <label>
                Price
                <input name="price" inputMode="decimal" pattern="[0-9]{1,8}(\.[0-9]{1,2})?" placeholder="0.00" required />
              </label>
              <label>
                Stock
                <input name="stock" inputMode="numeric" pattern="[0-9]+" placeholder="0" required />
              </label>
            </div>
            <button className="primary-button" type="submit" disabled={productBusy}>
              {productBusy ? 'Saving…' : 'Add product'} <span>↗</span>
            </button>
          </form>

          <div className="catalog-header">
            <span>PRODUCT CATALOG</span>
            <span>{products.length} {products.length === 1 ? 'ITEM' : 'ITEMS'}</span>
          </div>

          {productsLoading ? (
            <div className="catalog-empty">Loading products…</div>
          ) : products.length === 0 ? (
            <div className="catalog-empty">No products yet. Add the first item using the form above.</div>
          ) : (
            <div className="product-list">
              {products.map((product) => (
                <article className="product-card" key={product.id}>
                  {String(editingProduct) === String(product.id) ? (
                    <form className="product-edit-form" onSubmit={(event) => updateProduct(event, product.id)}>
                      <div className="product-card-title">
                        <div>
                          <span className="product-id">PRODUCT / {String(product.id).padStart(3, '0')}</span>
                          <h3>Edit product</h3>
                        </div>
                        <button className="text-button" type="button" onClick={() => setEditingProduct(null)}>Cancel</button>
                      </div>
                      <label>
                        Name
                        <input name="name" defaultValue={product.name} maxLength="150" required />
                      </label>
                      <label>
                        Description
                        <textarea name="description" defaultValue={product.description || ''} rows="2" />
                      </label>
                      <div className="product-form-numbers">
                        <label>
                          Price
                          <input name="price" defaultValue={product.price} inputMode="decimal" pattern="[0-9]{1,8}(\.[0-9]{1,2})?" required />
                        </label>
                        <label>
                          Stock
                          <input name="stock" defaultValue={product.stock} inputMode="numeric" pattern="[0-9]+" required />
                        </label>
                      </div>
                      <button className="primary-button" type="submit" disabled={productBusy}>
                        {productBusy ? 'Saving…' : 'Save changes'} <span>↗</span>
                      </button>
                    </form>
                  ) : (
                    <>
                      <div className="product-card-main">
                        <span className="product-id">PRODUCT / {String(product.id).padStart(3, '0')}</span>
                        <h3>{product.name}</h3>
                        <p>{product.description || 'No description provided.'}</p>
                      </div>
                      <div className="product-card-stats">
                        <div><span>PRICE</span><strong>${Number(product.price).toFixed(2)}</strong></div>
                        <div><span>IN STOCK</span><strong>{product.stock}</strong></div>
                      </div>
                      <div className="product-card-actions">
                        <button className="text-button" type="button" disabled={productBusy} onClick={() => setEditingProduct(product.id)}>Edit product <span>↗</span></button>
                        <button className="delete-button" type="button" disabled={productBusy} onClick={() => deleteProduct(product)}>Delete</button>
                      </div>
                    </>
                  )}
                </article>
              ))}
            </div>
          )}
        </section>
      )}

      <footer className="footer">
        <span>BUILT FOR THE PEOPLE BEHIND THE WORK.</span>
        <span>© {new Date().getFullYear()} LAVALUST <span className="footer-divider">/</span> ACCESS PORTAL</span>
      </footer>
    </main>
  );
}

export default App;
