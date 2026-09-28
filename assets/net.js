// ตัวช่วยเรียก api.php พร้อม CSRF (ไม่มีผลข้างเคียง import ได้จากทุกหน้า)
export const csrf = () => document.querySelector('meta[name="csrf"]')?.content || '';

export async function api(route, body) {
  const opt = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (body) {
    opt.headers['X-CSRF-Token'] = csrf();
    opt.body = body instanceof FormData ? body : JSON.stringify(body);
    if (!(body instanceof FormData)) opt.headers['Content-Type'] = 'application/json';
  }
  const [r, qs] = route.split('?');
  const res = await fetch('api.php?r=' + encodeURIComponent(r) + (qs ? '&' + qs : ''), opt);
  const type = res.headers.get('content-type') || '';
  // โฮสต์ฟรีบางครั้งตอบหน้ากันบอต (HTML 200) แทน JSON → ถือว่าล้มเหลว
  if (!type.includes('json')) throw new Error('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ กรุณารีเฟรชหน้าแล้วลองใหม่');
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'ทำรายการไม่สำเร็จ');
  return data;
}

