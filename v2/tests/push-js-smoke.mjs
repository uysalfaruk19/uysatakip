import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";

const source = fs.readFileSync(new URL("../public/assets/push.js", import.meta.url), "utf8");

function classList() {
  const values = new Set();
  return {
    add: (...names) => names.forEach((name) => values.add(name)),
    remove: (...names) => names.forEach((name) => values.delete(name)),
    toggle: (name, force) => {
      if (force === true) values.add(name);
      else if (force === false) values.delete(name);
      else if (values.has(name)) values.delete(name);
      else values.add(name);
    },
    contains: (name) => values.has(name),
  };
}

function element(tag = "div") {
  return {
    tagName: tag.toUpperCase(),
    className: "",
    classList: classList(),
    style: {},
    children: [],
    parentNode: null,
    textContent: "",
    hidden: false,
    appendChild(child) {
      child.parentNode = this;
      this.children.push(child);
      return child;
    },
    removeChild(child) {
      this.children = this.children.filter((item) => item !== child);
      child.parentNode = null;
    },
    setAttribute() {},
    addEventListener(name, handler) {
      this.listeners ||= {};
      this.listeners[name] = handler;
    },
    closest() {
      return null;
    },
  };
}

const documentListeners = {};
const windowListeners = {};
const pluginListeners = {};
const body = element("body");
const documentElement = element("html");
const document = {
  body,
  documentElement,
  createElement: (tag) => element(tag),
  querySelector(selector) {
    if (selector === ".native-push-toast") {
      return body.children.find((item) => item.className === "native-push-toast") || null;
    }
    return null;
  },
  querySelectorAll: () => [],
  addEventListener(name, handler) {
    documentListeners[name] = handler;
  },
};

const storage = new Map();
const fetchCalls = [];
const assigned = [];
let cleared = 0;
let registered = 0;
let reloaded = 0;
const PushNotifications = {
  addListener(name, handler) {
    pluginListeners[name] = handler;
    return Promise.resolve();
  },
  removeAllDeliveredNotifications() {
    cleared++;
    return Promise.resolve();
  },
  checkPermissions: () => Promise.resolve({ receive: "granted" }),
  requestPermissions: () => Promise.resolve({ receive: "granted" }),
  register() {
    registered++;
    return Promise.resolve();
  },
};

const window = {
  Capacitor: {
    isNativePlatform: () => true,
    getPlatform: () => "ios",
    Plugins: { PushNotifications },
  },
  UYSA_NATIVE_CONTEXT: {
    authenticated: true,
    guard: "customer",
    pushEndpoint: "/m/push-register.php",
  },
  location: {
    origin: "https://app.yemekhaneci.com.tr",
    pathname: "/m/panel.php",
    assign: (path) => assigned.push(path),
    reload: () => reloaded++,
  },
  scrollY: 0,
  addEventListener(name, handler) {
    windowListeners[name] = handler;
  },
};

const context = vm.createContext({
  window,
  document,
  URL,
  Promise,
  JSON,
  Math,
  localStorage: {
    getItem: (key) => storage.get(key) || null,
    setItem: (key, value) => storage.set(key, String(value)),
  },
  fetch: (...args) => {
    fetchCalls.push(args);
    return Promise.resolve({ ok: true, status: 200 });
  },
  requestAnimationFrame: (callback) => callback(),
  setTimeout,
  clearTimeout,
});

vm.runInContext(source, context, { filename: "push.js" });
await new Promise((resolve) => setTimeout(resolve, 0));

assert.equal(documentElement.classList.contains("native-app"), true);
assert.equal(body.classList.contains("native-app"), true);
assert.equal(cleared, 1, "app açılışında bildirimler ve badge temizlenir");
assert.equal(registered, 1, "izin varsa APNs kaydı yenilenir");
assert.equal(typeof pluginListeners.pushNotificationActionPerformed, "function");

pluginListeners.pushNotificationActionPerformed({
  notification: { data: { url: "/m/menu.php?ay=2026-07#bugun" } },
});
assert.equal(assigned.at(-1), "/m/menu.php?ay=2026-07#bugun");

for (const unsafe of ["//evil.example/x", "https://evil.example/x", "javascript:alert(1)", "/\\evil.example/x"]) {
  const before = assigned.length;
  pluginListeners.pushNotificationActionPerformed({ notification: { data: { url: unsafe } } });
  assert.equal(assigned.length, before, `güvensiz deep-link reddedildi: ${unsafe}`);
}

pluginListeners.registration({ value: "token-123" });
await new Promise((resolve) => setTimeout(resolve, 0));
assert.equal(fetchCalls.at(-1)[0], "/m/push-register.php");
assert.match(fetchCalls.at(-1)[1].body, /token-123/);

pluginListeners.pushNotificationReceived({
  title: "Menünüz yayınlandı",
  body: "Temmuz Menüsü",
  data: { url: "/m/menu.php" },
});
assert.ok(body.children.some((item) => item.className === "native-push-toast"));

const touchTarget = element("main");
documentListeners.touchstart({ target: touchTarget, touches: [{ clientX: 20, clientY: 0 }] });
let prevented = false;
documentListeners.touchmove({
  touches: [{ clientX: 22, clientY: 140 }],
  preventDefault: () => {
    prevented = true;
  },
});
documentListeners.touchend();
await new Promise((resolve) => setTimeout(resolve, 150));
assert.equal(prevented, true, "dikey çekme native bounce yerine app yenilemeyi kullanır");
assert.equal(reloaded, 1, "eşik aşılınca sayfa bir kez yenilenir");

console.log("push-js smoke ok");
