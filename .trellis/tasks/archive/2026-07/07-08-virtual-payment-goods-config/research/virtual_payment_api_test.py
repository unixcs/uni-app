#!/usr/bin/env python3
import copy
import json
import subprocess
import sys
import time
import urllib.request
import urllib.error

BASE = 'http://127.0.0.1/index.php?s=/store'
DB = ['mysql', '-N', '-B', '-uyoshop', '-pyoshop123', '-h127.0.0.1', 'yoshop2']
ADMIN_ID = 10001
ADMIN_USER = 'admin'
ADMIN_TEMP_PASS = 'CodexTmp#20260709'
FIXTURE_GOODS_ID = 10004

results = []
created_goods_ids = []


def sh(cmd):
    return subprocess.check_output(cmd, text=True).strip()


def mysql_scalar(sql):
    return sh(DB + ['-e', sql])


def mysql_exec(sql):
    subprocess.check_call(DB + ['-e', sql])


def php_password_hash(password):
    return sh(['php', '-r', f"echo password_hash({password!r}, PASSWORD_DEFAULT);"])


def http_json(method, url, payload=None, headers=None):
    data = None
    req_headers = {'Content-Type': 'application/json'}
    if headers:
        req_headers.update(headers)
    if payload is not None:
        data = json.dumps(payload, ensure_ascii=False).encode('utf-8')
    req = urllib.request.Request(url, data=data, headers=req_headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            body = resp.read().decode('utf-8')
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8')
    return json.loads(body)


def api_post(path, token=None, payload=None):
    headers = {}
    if token:
        headers['Access-Token'] = token
    return http_json('POST', BASE + path, payload, headers)


def api_get(path, token=None):
    headers = {}
    if token:
        headers['Access-Token'] = token
    return http_json('GET', BASE + path, None, headers)


def expect(condition, name, detail):
    if not condition:
        raise AssertionError(f'{name}: {detail}')
    results.append({'name': name, 'status': 'PASS', 'detail': detail})


def fail(name, detail):
    results.append({'name': name, 'status': 'FAIL', 'detail': detail})


def build_form_from_detail(goods):
    sku = goods['skuList'][0]
    return {
        'goods_type': goods['goods_type'],
        'goods_name': goods['goods_name'],
        'goods_no': goods['goods_no'],
        'imagesIds': [img['file_id'] for img in goods['goods_images']],
        'categoryIds': goods['categoryIds'],
        'status': goods['status'],
        'sort': goods['sort'],
        'spec_type': goods['spec_type'],
        'goods_price': float(sku['goods_price']),
        'line_price': float(sku['line_price']),
        'stock_num': int(sku['stock_num']),
        'deduct_stock_type': goods['deduct_stock_type'],
        'is_restrict': goods['is_restrict'],
        'restrict_total': goods['restrict_total'],
        'restrict_single': goods['restrict_single'],
        'content': goods['content'],
        'selling_point': goods['selling_point'],
        'serviceIds': goods['serviceIds'],
        'sales_initial': goods['sales_initial'],
        'is_points_gift': goods['is_points_gift'],
        'is_points_discount': goods['is_points_discount'],
        'is_enable_grade': goods['is_enable_grade'],
        'is_alone_grade': goods['is_alone_grade'],
        'delivery_type': goods['delivery_type'],
        'vp_enabled': goods['vp_enabled'],
        'vp_product_id': goods['vp_product_id'],
        'vp_product_name': goods.get('vp_product_name', ''),
        'vp_price_snapshot': goods['vp_price_snapshot'],
    }


def build_ui_form_from_detail(goods):
    form = build_form_from_detail(goods)
    form.pop('delivery_type', None)
    return form


def get_goods_detail(token, goods_id):
    resp = api_get(f'/goods/detail&goodsId={goods_id}', token)
    expect(resp['status'] == 200, f'detail-{goods_id}', f"detail status={resp['status']}")
    return resp['data']['goodsInfo']


def edit_goods(token, goods_id, form, case_name):
    resp = api_post(f'/goods/edit&goodsId={goods_id}', token, {'form': form})
    if resp['status'] != 200:
        raise AssertionError(f'{case_name}: {resp}')
    results.append({'name': case_name, 'status': 'PASS', 'detail': resp['message']})
    return resp


def expect_edit_error(token, goods_id, form, case_name, expected_message_part):
    resp = api_post(f'/goods/edit&goodsId={goods_id}', token, {'form': form})
    expect(resp['status'] == 500, case_name, f"status=500 message={resp['message']}")
    expect(expected_message_part in resp['message'], case_name + '-message', resp['message'])


def add_goods(token, form):
    resp = api_post('/goods/add', token, {'form': form})
    expect(resp['status'] == 200, 'add-success', resp['message'])
    goods_id = int(mysql_scalar(f"SELECT goods_id FROM yoshop_goods WHERE goods_name={json.dumps(form['goods_name'], ensure_ascii=False)} AND is_delete=0 ORDER BY goods_id DESC LIMIT 1;"))
    created_goods_ids.append(goods_id)
    return goods_id


def delete_goods(token, goods_id):
    resp = api_post('/goods/delete', token, {'goodsIds': [goods_id]})
    expect(resp['status'] == 200, f'delete-{goods_id}', resp['message'])


def verify_goods(goods, price, product_id, product_name, snapshot, enabled=1):
    expect(int(goods['vp_enabled']) == enabled, f'verify-enabled-{goods["goods_id"]}', f"vp_enabled={goods['vp_enabled']}")
    expect(goods['vp_product_id'] == product_id, f'verify-productid-{goods["goods_id"]}', goods['vp_product_id'])
    expect(goods.get('vp_product_name', '') == product_name, f'verify-productname-{goods["goods_id"]}', goods.get('vp_product_name', ''))
    expect(int(goods['vp_price_snapshot']) == snapshot, f'verify-snapshot-{goods["goods_id"]}', str(goods['vp_price_snapshot']))
    expect(str(goods['goods_price_min']) == f'{price:.2f}', f'verify-price-{goods["goods_id"]}', str(goods['goods_price_min']))


def main():
    original_hash = mysql_scalar(f"SELECT password FROM yoshop_store_user WHERE store_user_id={ADMIN_ID};")
    temp_hash = php_password_hash(ADMIN_TEMP_PASS)
    base_form = None
    token = None
    try:
        # DB schema readiness
        has_col = mysql_scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='yoshop2' AND TABLE_NAME='yoshop_goods' AND COLUMN_NAME='vp_product_name';")
        expect(has_col == '1', 'schema-vp_product_name', 'vp_product_name column exists')

        # Runtime/login smoke
        login_fail = api_post('/passport/login', payload={'username': '__no_such_user__', 'password': 'bad'})
        expect(login_fail['status'] == 500 and '不存在' in login_fail['message'], 'login-invalid-smoke', login_fail['message'])

        mysql_exec(f"UPDATE yoshop_store_user SET password={json.dumps(temp_hash)} WHERE store_user_id={ADMIN_ID};")
        login_ok = api_post('/passport/login', payload={'username': ADMIN_USER, 'password': ADMIN_TEMP_PASS})
        expect(login_ok['status'] == 200, 'login-valid', login_ok['message'])
        token = login_ok['data']['token']
        expect(bool(token), 'login-token', 'token received')

        goods_list = api_get('/goods/list&page=1', token)
        expect(goods_list['status'] == 200, 'goods-list', f"total={goods_list['data']['list']['total']}")

        original_detail = get_goods_detail(token, FIXTURE_GOODS_ID)
        base_form = build_form_from_detail(original_detail)
        ui_base_form = build_ui_form_from_detail(original_detail)

        # Case 1: backend owns rule; manual mismatch gets canonicalized to vip158
        case = copy.deepcopy(base_form)
        case.update({'goods_price': 158, 'vp_enabled': 1, 'vp_product_id': 'manual-wrong', 'vp_product_name': 'manual-wrong', 'vp_price_snapshot': 1})
        edit_goods(token, FIXTURE_GOODS_ID, case, 'edit-158-success')
        detail = get_goods_detail(token, FIXTURE_GOODS_ID)
        verify_goods(detail, 158.00, 'vip158', 'vip158', 15800, 1)

        # Case 2: 0.01 -> vip001
        case = copy.deepcopy(base_form)
        case.update({'goods_price': 0.01, 'vp_enabled': 1, 'vp_product_id': 'x', 'vp_product_name': 'y', 'vp_price_snapshot': 999})
        edit_goods(token, FIXTURE_GOODS_ID, case, 'edit-001-success')
        detail = get_goods_detail(token, FIXTURE_GOODS_ID)
        verify_goods(detail, 0.01, 'vip001', 'vip001', 1, 1)

        # Case 3: 0.11 -> vip011
        case = copy.deepcopy(base_form)
        case.update({'goods_price': 0.11, 'vp_enabled': 1, 'vp_product_id': 'x', 'vp_product_name': 'y', 'vp_price_snapshot': 999})
        edit_goods(token, FIXTURE_GOODS_ID, case, 'edit-011-success')
        detail = get_goods_detail(token, FIXTURE_GOODS_ID)
        verify_goods(detail, 0.11, 'vip011', 'vip011', 11, 1)

        # Case 4: >=1 non-integer rejected
        case = copy.deepcopy(base_form)
        case.update({'goods_price': 9.9, 'vp_enabled': 1, 'vp_product_id': 'vip990', 'vp_product_name': 'vip990', 'vp_price_snapshot': 990})
        expect_edit_error(token, FIXTURE_GOODS_ID, case, 'edit-9.9-rejected', '必须为整数')

        # Case 5: non-service goods rejected when VP enabled
        case = copy.deepcopy(base_form)
        case.update({'goods_price': 88, 'vp_enabled': 1, 'serviceIds': [], 'delivery_type': [10], 'vp_product_id': 'vip88', 'vp_product_name': 'vip88', 'vp_price_snapshot': 8800})
        expect_edit_error(token, FIXTURE_GOODS_ID, case, 'edit-nonservice-rejected', '服务商品')

        # Case 6: disable VP clears fields
        case = copy.deepcopy(base_form)
        case.update({'goods_price': 88, 'vp_enabled': 0, 'vp_product_id': 'keep-me', 'vp_product_name': 'keep-me', 'vp_price_snapshot': 8800})
        edit_goods(token, FIXTURE_GOODS_ID, case, 'edit-disable-clears')
        detail = get_goods_detail(token, FIXTURE_GOODS_ID)
        verify_goods(detail, 88.00, '', '', 0, 0)

        # Case 7: merchant UI payload omits delivery_type, backend must still own service validation context
        case = copy.deepcopy(ui_base_form)
        case.update({'goods_price': 0.02, 'vp_enabled': 1, 'vp_product_id': 'vip002', 'vp_product_name': 'vip002', 'vp_price_snapshot': 2})
        edit_goods(token, FIXTURE_GOODS_ID, case, 'edit-ui-payload-no-delivery_type-success')
        detail = get_goods_detail(token, FIXTURE_GOODS_ID)
        verify_goods(detail, 0.02, 'vip002', 'vip002', 2, 1)

        # Case 8: add flow canonicalizes from price
        unique = int(time.time())
        add_form = copy.deepcopy(base_form)
        add_form.update({
            'goods_name': f'VP自动化测试-{unique}',
            'goods_no': f'vp-auto-{unique}',
            'goods_price': 88,
            'vp_enabled': 1,
            'vp_product_id': 'manual-wrong',
            'vp_product_name': 'manual-wrong',
            'vp_price_snapshot': 123,
        })
        new_goods_id = add_goods(token, add_form)
        detail = get_goods_detail(token, new_goods_id)
        verify_goods(detail, 88.00, 'vip88', 'vip88', 8800, 1)

        # Case 9: merchant UI create payload omits delivery_type, backend still validates against system defaults
        ui_add_form = copy.deepcopy(ui_base_form)
        ui_add_form.update({
            'goods_name': f'VP自动化测试-UI-{unique}',
            'goods_no': f'vp-auto-ui-{unique}',
            'goods_price': 0.02,
            'vp_enabled': 1,
            'vp_product_id': 'vip002',
            'vp_product_name': 'vip002',
            'vp_price_snapshot': 2,
        })
        ui_goods_id = add_goods(token, ui_add_form)
        detail = get_goods_detail(token, ui_goods_id)
        verify_goods(detail, 0.02, 'vip002', 'vip002', 2, 1)

        # Case 10: add flow rejects 9.9
        bad_add = copy.deepcopy(add_form)
        bad_add.update({'goods_name': f'VP自动化测试-非法-{unique}', 'goods_no': f'vp-auto-bad-{unique}', 'goods_price': 9.9, 'vp_enabled': 1})
        bad_add_resp = api_post('/goods/add', token, {'form': bad_add})
        expect(bad_add_resp['status'] == 500, 'add-9.9-rejected', bad_add_resp['message'])
        expect('必须为整数' in bad_add_resp['message'], 'add-9.9-message', bad_add_resp['message'])

        # Cleanup newly added goods
        for gid in list(created_goods_ids):
            delete_goods(token, gid)
            created_goods_ids.remove(gid)

        print(json.dumps({'ok': True, 'results': results}, ensure_ascii=False, indent=2))
        return 0
    except Exception as exc:
        fail('test-run', str(exc))
        print(json.dumps({'ok': False, 'results': results}, ensure_ascii=False, indent=2))
        return 1
    finally:
        try:
            if base_form is not None and token:
                api_post(f'/goods/edit&goodsId={FIXTURE_GOODS_ID}', token, {'form': base_form})
        except Exception:
            pass
        for gid in list(created_goods_ids):
            try:
                if token:
                    api_post('/goods/delete', token, {'goodsIds': [gid]})
            except Exception:
                pass
        try:
            mysql_exec(f"UPDATE yoshop_store_user SET password={json.dumps(original_hash)} WHERE store_user_id={ADMIN_ID};")
        except Exception:
            pass


if __name__ == '__main__':
    sys.exit(main())
