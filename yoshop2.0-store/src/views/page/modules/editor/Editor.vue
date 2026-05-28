<template>
  <div class="editor">
    <!-- 标题 -->
    <div class="editor-title">
      <span>{{ selectedIndex === 'page' ? data.page.name : curItem.name }}</span>
    </div>

    <!-- 编辑器: 标题栏 -->
    <div v-if="selectedIndex === 'page'" class="editor-content">
      <a-tabs>
        <a-tab-pane key="1" tab="页面设置">
          <div class="block-box">
            <div class="block-title">基本信息</div>
            <div class="block-item has-tips">
              <span class="label">页面名称</span>
              <div class="flex-box">
                <a-input v-model="data.page.params.name" />
                <div class="tips">页面名称仅用于后台管理</div>
              </div>
            </div>
            <div class="block-item has-tips">
              <span class="label">分享标题</span>
              <div class="flex-box">
                <a-input v-model="data.page.params.shareTitle" />
                <div class="tips">用户端转发时显示的标题</div>
              </div>
            </div>
          </div>
        </a-tab-pane>
        <a-tab-pane key="2" tab="标题栏设置">
          <div class="block-box">
            <div class="block-title">标题栏设置</div>
            <div class="block-item has-tips">
              <span class="label">标题名称</span>
              <div class="flex-box">
                <a-input v-model="data.page.params.title" />
                <div class="tips">用户端端顶部显示的标题</div>
              </div>
            </div>
            <div class="block-item">
              <span class="label">文字颜色</span>
              <a-radio-group buttonStyle="solid" v-model="data.page.style.titleTextColor">
                <a-radio-button value="white">白色</a-radio-button>
                <a-radio-button value="black">黑色</a-radio-button>
              </a-radio-group>
            </div>
            <div class="block-item">
              <span class="label">标题栏背景</span>
              <div class="item-colorPicker">
                <span
                  class="rest-color"
                  @click="onEditorResetColor(data.page.style, 'titleBackgroundColor', '#fff')"
                >重置</span>
                <MyColorPicker v-model="data.page.style.titleBackgroundColor" />
              </div>
            </div>
          </div>
        </a-tab-pane>
      </a-tabs>
    </div>

    <template v-if="data.items.length && curItem">
      <!-- 搜索栏 -->
      <div v-if="curItem.type == 'search'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="block-box">
              <div class="block-title">功能设置</div>
              <div class="block-item">
                <span class="label">提示文字</span>
                <a-input v-model="curItem.params.placeholder" />
              </div>
              <div class="block-item">
                <span class="label">吸顶规则</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.params.sticky">
                  <a-radio-button :value="false">不吸顶</a-radio-button>
                  <a-radio-button :value="true">到顶部时吸顶</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item tips">
                <div class="tips">滚动至页面顶部时固定</div>
              </div>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">内容样式</div>
              <div class="block-item">
                <span class="label">搜索框样式</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.style.searchStyle">
                  <a-radio-button value="round">圆弧</a-radio-button>
                  <a-radio-button value="radius">圆角</a-radio-button>
                  <a-radio-button value="square">方形</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item">
                <span class="label">文字对齐</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.style.textAlign">
                  <a-radio-button value="left">居左</a-radio-button>
                  <a-radio-button value="center">居中</a-radio-button>
                  <a-radio-button value="right">居右</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item">
                <span class="label">搜索框颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'searchBg', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.searchBg" />
                </div>
              </div>
              <div class="block-item">
                <span class="label">文字颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'searchFontColor', '#999999')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.searchFontColor" />
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#f1f1f2')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingY" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingY }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">左右边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingX" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingX }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 辅助空白 -->
      <div v-if="curItem.type == 'blank'" class="editor-content">
        <div class="block-box">
          <div class="block-title">样式设置</div>
          <div class="block-item">
            <span class="label">组件高度</span>
            <div class="item-slider">
              <a-slider v-model="curItem.style.height" :min="5" :max="200" />
              <span class="unit-text">
                <span>{{ curItem.style.height }}</span>
                <span>像素</span>
              </span>
            </div>
          </div>
          <div class="block-item">
            <span class="label">背景颜色</span>
            <div class="item-colorPicker">
              <span
                class="rest-color"
                @click="onEditorResetColor(curItem.style, 'background', '#fff')"
              >重置</span>
              <MyColorPicker v-model="curItem.style.background" />
            </div>
          </div>
        </div>
      </div>

      <!-- 辅助线 -->
      <div v-if="curItem.type == 'guide'" class="editor-content">
        <div class="block-box">
          <div class="block-title">样式设置</div>
          <div class="block-item">
            <span class="label">线条样式</span>
            <a-radio-group buttonStyle="solid" v-model="curItem.style.lineStyle">
              <a-radio-button value="solid">实线</a-radio-button>
              <a-radio-button value="dashed">虚线</a-radio-button>
              <a-radio-button value="dotted">点状</a-radio-button>
            </a-radio-group>
          </div>
          <div class="block-item">
            <span class="label">线条颜色</span>
            <div class="item-colorPicker">
              <span
                class="rest-color"
                @click="onEditorResetColor(curItem.style, 'lineColor', '#000')"
              >重置</span>
              <MyColorPicker v-model="curItem.style.lineColor" />
            </div>
          </div>
          <div class="block-item">
            <span class="label">线条高度</span>
            <div class="item-slider">
              <a-slider v-model="curItem.style.lineHeight" :min="1" :max="20" />
              <span class="unit-text">
                <span>{{ curItem.style.lineHeight }}</span>
                <span>像素</span>
              </span>
            </div>
          </div>
          <div class="block-item">
            <span class="label">上下边距</span>
            <div class="item-slider">
              <a-slider v-model="curItem.style.paddingTop" :min="0" :max="50" />
              <span class="unit-text">
                <span>{{ curItem.style.paddingTop }}</span>
                <span>像素</span>
              </span>
            </div>
          </div>
          <div class="block-item">
            <span class="label">背景颜色</span>
            <div class="item-colorPicker">
              <span
                class="rest-color"
                @click="onEditorResetColor(curItem.style, 'background', '#fff')"
              >重置</span>
              <MyColorPicker v-model="curItem.style.background" />
            </div>
          </div>
        </div>
      </div>

      <!-- 富文本 -->
      <div v-if="curItem.type == 'richText'" :style="{ width: '395px' }" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="block-box">
              <div class="block-title">文本内容</div>
              <div class="ueditor">
                <Ueditor v-model="curItem.params.content" :config="{ initialFrameWidth: 375 }" />
              </div>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingTop" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingTop }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">左右边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingLeft" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingLeft }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 店铺公告 -->
      <div v-if="curItem.type == 'notice'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="block-box">
              <div class="block-title">公告文案</div>
              <div class="block-item">
                <span class="label">内容</span>
                <a-input v-model="curItem.params.text" />
              </div>
              <div class="block-item">
                <span class="label">链接</span>
                <div class="flex-box">
                  <SLink v-model="curItem.params.link" />
                </div>
              </div>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">内容样式</div>
              <div class="block-item">
                <span class="label">文字大小</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.fontSize" :min="10" :max="18" />
                  <span class="unit-text">
                    <span>{{ curItem.style.fontSize }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">文字颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'textColor', '#000')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.textColor" />
                </div>
              </div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingTop" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingTop }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 文章组 -->
      <div v-if="curItem.type == 'article'" class="editor-content">
        <div class="block-box">
          <div class="block-title">文章内容</div>
          <div class="block-item">
            <span class="label">文章分类</span>
            <SArticleCate v-model="curItem.params.auto.category" />
          </div>
          <div class="block-item">
            <span class="label">显示数量</span>
            <div class="block-item-right">
              <a-input-number v-model="curItem.params.auto.showNum" :min="1" :max="20" />
              <span class="unit-text">
                <span>篇</span>
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- 在线客服 -->
      <div v-if="curItem.type == 'service'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="block-box">
              <div class="block-title">功能设置</div>
              <div class="block-item">
                <span class="label">客服类型</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.params.type">
                  <a-radio-button value="chat">在线聊天</a-radio-button>
                  <a-radio-button value="phone">拨打电话</a-radio-button>
                </a-radio-group>
              </div>
              <div v-show="curItem.params.type == 'phone'" class="block-item">
                <span class="label">电话号码</span>
                <a-input v-model="curItem.params.tel" />
              </div>
              <div class="block-item">
                <span class="label">客服图标</span>
                <span class="tips-wrap">建议尺寸：90×90</span>
                <SImage v-model="curItem.params.image" :width="60" :height="60" />
              </div>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">底边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.bottom" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.bottom }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">右边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.right" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.right }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">不透明度</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.opacity" :min="0" :max="100" />
                  <span class="unit-text">
                    <span>{{ curItem.style.opacity }}</span>
                    <span>%</span>
                  </span>
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 视频组 -->
      <div v-if="curItem.type == 'video'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="block-box">
              <div class="block-title">功能设置</div>
              <div class="block-item has-tips">
                <span class="label">视频地址</span>
                <div class="flex-box">
                  <a-input v-model="curItem.params.videoUrl" />
                  <div class="tips">仅支持.mp4格式的视频源地址</div>
                </div>
              </div>
              <div class="block-item has-tips">
                <span class="label">视频封面</span>
                <div class="flex-box">
                  <SImage v-model="curItem.params.poster" :width="160" :height="90" />
                  <div class="tips">建议封面图片尺寸与视频比例一致</div>
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">播放设置</div>
              <div class="block-item">
                <span class="label">自动播放</span>
                <span class="tips-wrap">仅支持小程序</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.params.autoplay">
                  <a-radio-button :value="1">开启</a-radio-button>
                  <a-radio-button :value="0">关闭</a-radio-button>
                </a-radio-group>
              </div>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">
                <span>内容样式</span>
                <span class="tips">视频宽度为750像素</span>
              </div>
              <div class="block-item">
                <span class="label">视频高度</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.height" :min="50" :max="400" />
                  <span class="unit-text">
                    <span>{{ curItem.style.height }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingTop" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingTop }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">左右边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingLeft" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingLeft }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#ffffff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 图片组 -->
      <div v-if="curItem.type == 'image'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="sub-title">添加图片 (最多10张，可拖动排序）</div>
            <draggable
              :list="curItem.data"
              v-bind="{ animation: 120, filter: 'input', preventOnFilter: false }"
            >
              <div v-for="(item, index) in curItem.data" :key="index" class="block-box drag">
                <div class="block-title">
                  <span class="left">图片 {{ index + 1 }}</span>
                  <a class="link" @click="handleDeleleData(curItem, index)">删除</a>
                </div>
                <div class="block-item">
                  <div class="block-item-common">
                    <div class="block-item-common-row">
                      <span class="label">图片</span>
                      <span class="label value">{{ item.imgName }}</span>
                    </div>
                    <div class="block-item-common-row">
                      <span class="label">链接</span>
                      <SLink v-model="item.link" />
                    </div>
                  </div>
                  <div class="block-item-custom">
                    <SImage
                      v-model="item.imgUrl"
                      tips="建议尺寸：宽750"
                      @update="item.imgName = $event.file_name"
                    />
                  </div>
                </div>
              </div>
            </draggable>
            <div v-if="curItem.data.length < 10" class="data-add">
              <a-button icon="plus" @click="handleAddData(10)">添加图片</a-button>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingTop" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingTop }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">左右边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingLeft" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingLeft }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">内容样式</div>
              <div class="block-item">
                <span class="label">图片圆角</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.borderRadius" :min="0" :max="30" />
                  <span class="unit-text">
                    <span>{{ curItem.style.borderRadius }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">图片间距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.itemMargin" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.itemMargin }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 轮播图 -->
      <div v-if="curItem.type == 'banner'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="sub-title">添加图片 (最多10张，可拖动排序）</div>
            <draggable
              :list="curItem.data"
              v-bind="{ animation: 120, filter: 'input', preventOnFilter: false }"
            >
              <div v-for="(item, index) in curItem.data" :key="index" class="block-box drag">
                <div class="block-title">
                  <span class="left">图片 {{ index + 1 }}</span>
                  <a class="link" @click="handleDeleleData(curItem, index)">删除</a>
                </div>
                <div class="block-item">
                  <div class="block-item-common">
                    <div class="block-item-common-row">
                      <span class="label">图片</span>
                      <span class="label value">{{ item.imgName }}</span>
                    </div>
                    <div class="block-item-common-row">
                      <span class="label">链接</span>
                      <SLink v-model="item.link" />
                    </div>
                  </div>
                  <div class="block-item-custom">
                    <SImage
                      v-model="item.imgUrl"
                      tips="建议尺寸：750×400"
                      @update="item.imgName = $event.file_name"
                    />
                  </div>
                </div>
              </div>
            </draggable>
            <div v-if="curItem.data.length < 10" class="data-add">
              <a-button icon="plus" @click="handleAddData(10)">添加图片</a-button>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingTop" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingTop }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">左右边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingLeft" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingLeft }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">内容样式</div>
              <div class="block-item">
                <span class="label">指示点形状</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.style.btnShape">
                  <a-radio-button value="round">圆形</a-radio-button>
                  <a-radio-button value="square">正方形</a-radio-button>
                  <a-radio-button value="rectangle">长方形</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item">
                <span class="label">指示点颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'btnColor', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.btnColor" />
                </div>
              </div>
              <div class="block-item">
                <span class="label">切换时间</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.interval" :step="1" :min="1" :max="20" />
                  <span class="unit-text">
                    <span>{{ curItem.style.interval }}</span>
                    <span>秒</span>
                  </span>
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">图片样式</div>
              <div class="block-item">
                <span class="label">图片圆角</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.borderRadius" :min="0" :max="30" />
                  <span class="unit-text">
                    <span>{{ curItem.style.borderRadius }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 商品组 -->
      <div v-if="curItem.type == 'goods'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="block-box">
              <div class="block-title">
                <span>商品来源</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.params.source">
                  <a-radio-button value="auto">自动获取</a-radio-button>
                  <a-radio-button value="choice">手动选择</a-radio-button>
                </a-radio-group>
              </div>
            </div>
            <!-- 手动选择 -->
            <div v-if="curItem.params.source === 'choice'" class="block-box">
              <div class="block-title">选择商品 ({{ curItem.data.length }})</div>
              <SGoods v-model="curItem.data" />
            </div>
            <!-- 自动获取 -->
            <div v-if="curItem.params.source === 'auto'" class="block-box">
              <div class="block-title">商品数据</div>
              <div class="block-item">
                <span class="label">商品分类</span>
                <SelectCategory v-model="curItem.params.auto.category" />
              </div>
              <div class="block-item">
                <span class="label">商品排序</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.params.auto.goodsSort">
                  <a-radio-button value="all">默认</a-radio-button>
                  <a-radio-button value="sales">销量</a-radio-button>
                  <a-radio-button value="price">价格</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item">
                <span class="label">显示数量</span>
                <div class="block-item-right">
                  <a-input-number v-model="curItem.params.auto.showNum" :min="1" :max="50" />
                  <span class="unit-text">
                    <span>件</span>
                  </span>
                </div>
              </div>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">商品样式</div>
              <div class="block-item">
                <span class="label">显示类型</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.style.display">
                  <a-radio-button value="list">列表平铺</a-radio-button>
                  <a-radio-button :disabled="curItem.style.column === 1" value="slide">横向滑动</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item">
                <span class="label">分列数量</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.style.column">
                  <a-radio-button :disabled="curItem.style.display !== 'list'" :value="1">单列</a-radio-button>
                  <a-radio-button :value="2">两列</a-radio-button>
                  <a-radio-button :value="3">三列</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item">
                <span class="label">显示内容</span>
                <div class="item-checkbox" :style="{ width: '180px' }">
                  <a-checkbox-group v-model="curItem.style.show">
                    <a-checkbox value="goodsName">商品名称</a-checkbox>
                    <a-checkbox value="goodsPrice">商品价格</a-checkbox>
                    <a-checkbox value="linePrice">划线价格</a-checkbox>
                    <a-checkbox value="sellingPoint">商品卖点</a-checkbox>
                    <a-checkbox value="goodsSales">商品销量</a-checkbox>
                    <a-checkbox v-show="curItem.style.column < 3" value="cartBtn">加购按钮</a-checkbox>
                  </a-checkbox-group>
                </div>
              </div>
              <div class="block-item">
                <span class="label">商品价格颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'priceColor', '#ff1051')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.priceColor" />
                </div>
              </div>
              <div class="block-item">
                <span class="label">商品卖点颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'sellingColor', '#e3771f')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.sellingColor" />
                </div>
              </div>
              <div class="block-item">
                <span class="label">商品名称</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.style.goodsNameRows">
                  <a-radio-button value="one">单行</a-radio-button>
                  <a-radio-button value="two">双行</a-radio-button>
                </a-radio-group>
              </div>
              <template v-if="inArray('cartBtn', curItem.style.show)">
                <div class="block-item">
                  <span class="label">购物车按钮样式</span>
                  <a-radio-group buttonStyle="solid" v-model="curItem.style.btnCartStyle">
                    <a-radio-button :value="1">样式1</a-radio-button>
                    <a-radio-button :value="2">样式2</a-radio-button>
                    <a-radio-button :value="3">样式3</a-radio-button>
                  </a-radio-group>
                </div>
                <div class="block-item">
                  <span class="label">购物车按钮颜色</span>
                  <div class="item-colorPicker">
                    <span
                      class="rest-color"
                      @click="onEditorResetColor(curItem.style, 'btnCartColor', '#27c29a')"
                    >重置</span>
                    <MyColorPicker v-model="curItem.style.btnCartColor" />
                  </div>
                </div>
              </template>
            </div>
            <div class="block-box">
              <div class="block-title">卡片样式</div>
              <div class="block-item">
                <span class="label">内容样式</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.style.cardType">
                  <a-radio-button value="flat">扁平</a-radio-button>
                  <a-radio-button value="card">卡片</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item">
                <span class="label">圆角尺寸</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.borderRadius" :min="0" :max="20" />
                  <span class="unit-text">
                    <span>{{ curItem.style.borderRadius }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">商品间距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.itemMargin" :min="0" :max="30" />
                  <span class="unit-text">
                    <span>{{ curItem.style.itemMargin }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingY" :min="0" :max="30" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingY }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">左右边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingX" :min="0" :max="20" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingX }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#ffffff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 导航组 -->
      <div v-if="curItem.type == 'navBar'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="sub-title">添加导航 (最少4个，最多10个，可拖动排序)</div>
            <draggable
              :list="curItem.data"
              v-bind="{ animation: 120, filter: 'input', preventOnFilter: false }"
            >
              <div v-for="(item, index) in curItem.data" :key="index" class="block-box drag">
                <div class="block-title">
                  <span class="left">导航 {{ index + 1 }}</span>
                  <a class="link" @click="handleDeleleData(curItem, index)">删除</a>
                </div>
                <div class="block-item">
                  <div class="block-item-common">
                    <div class="block-item-common-row">
                      <span class="label">名称</span>
                      <a-input v-model="item.text" />
                    </div>
                    <div class="block-item-common-row">
                      <span class="label">链接</span>
                      <SLink v-model="item.link" />
                    </div>
                  </div>
                  <div class="block-item-custom">
                    <SImage v-model="item.imgUrl" tips="建议尺寸：100×100" />
                  </div>
                </div>
              </div>
            </draggable>
            <div v-if="curItem.data.length < 10" class="data-add">
              <a-button icon="plus" @click="handleAddData(10)">添加导航</a-button>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">内容样式</div>
              <div class="block-item">
                <span class="label">每行数量</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.style.rowsNum">
                  <a-radio-button :value="3">3个</a-radio-button>
                  <a-radio-button :value="4">4个</a-radio-button>
                  <a-radio-button :value="5">5个</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item">
                <span class="label">图片大小</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.imageSize" :min="30" :max="90" />
                  <span class="unit-text">
                    <span>{{ curItem.style.imageSize }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">文字颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'textColor', '#000')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.textColor" />
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingTop" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingTop }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 图片橱窗 -->
      <div v-if="curItem.type == 'window'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="sub-title">添加图片 (最多10个，可拖动排序)</div>
            <draggable
              :list="curItem.data"
              v-bind="{ animation: 120, filter: 'input', preventOnFilter: false }"
            >
              <div v-for="(item, index) in curItem.data" :key="index" class="block-box drag">
                <div class="block-title">
                  <span class="left">图片 {{ index + 1 }}</span>
                  <a class="link" @click="handleDeleleData(curItem, index)">删除</a>
                </div>
                <div class="block-item">
                  <div class="block-item-common">
                    <div class="block-item-common-row">
                      <span class="label">名称</span>
                      <span class="label value">{{ item.imgName }}</span>
                    </div>
                    <div class="block-item-common-row">
                      <span class="label">链接</span>
                      <SLink v-model="item.link" />
                    </div>
                  </div>
                  <div class="block-item-custom">
                    <SImage v-model="item.imgUrl" @update="item.imgName = $event.file_name" />
                  </div>
                </div>
              </div>
            </draggable>
            <div v-if="curItem.data.length < 10" class="data-add">
              <a-button icon="plus" @click="handleAddData(10)">添加图片</a-button>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">内容样式</div>
              <div class="block-item">
                <span class="label">每行数量</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.style.layout">
                  <a-radio-button :value="2">2列</a-radio-button>
                  <a-radio-button :value="3">3列</a-radio-button>
                  <a-radio-button :value="4">4列</a-radio-button>
                  <a-radio-button :value="-1">橱窗</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider" :style="{ width: '210px' }">
                  <a-slider v-model="curItem.style.paddingTop" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingTop }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">左右边距</span>
                <div class="item-slider" :style="{ width: '210px' }">
                  <a-slider v-model="curItem.style.paddingLeft" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingLeft }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 热区组 -->
      <div v-if="curItem.type == 'hotZone'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="sub-title">实现点击图片不同位置，跳转不同的链接</div>
            <div class="block-box">
              <div class="block-title">
                <span class="left">背景图片</span>
              </div>
              <div class="block-item">
                <div class="block-item-common">
                  <div class="block-item-common-row">
                    <span class="label">图片</span>
                    <span class="label value">{{ curItem.data.imgName }}</span>
                  </div>
                  <div class="block-item-common-row">
                    <span class="label">链接</span>
                    <SHotZone v-model="curItem.data.maps" :image="curItem.data.imgUrl" />
                  </div>
                </div>
                <div class="block-item-custom">
                  <SImage
                    v-model="curItem.data.imgUrl"
                    tips="建议尺寸：宽750"
                    @update="curItem.data.imgName = $event.file_name"
                  />
                </div>
              </div>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingTop" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingTop }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">左右边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingLeft" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingLeft }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">内容样式</div>
              <div class="block-item">
                <span class="label">图片圆角</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.borderRadius" :min="0" :max="30" />
                  <span class="unit-text">
                    <span>{{ curItem.style.borderRadius }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 关注公众号 -->
      <div v-if="curItem.type == 'officialAccount'" class="editor-content">
        <div class="block-box">
          <div class="text-describe">
            <p>1、该组件仅支持微信小程序端，其他端不显示</p>
            <p class="empty"></p>
            <p>
              2、使用组件前，需前往
              <a href="https://mp.weixin.qq.com" target="_blank">小程序后台</a>，在“设置”->“接口设置”->“公众号关注组件”中设置要展示的公众号。
            </p>
            <p class="x-mb-10">注：设置的公众号需与小程序主体一致。</p>
            <p class="empty"></p>
            <p>3、当小程序从扫二维码场景（场景值1011）打开时，才具有展示引导关注公众号组件的能力。</p>
          </div>
        </div>
      </div>

      <!-- 优惠券 -->
      <div v-if="curItem.type == 'coupon'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="block-box">
              <div class="block-title">
                <span>数据来源</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.params.source">
                  <a-radio-button value="auto">自动获取</a-radio-button>
                  <a-radio-button value="choice">手动选择</a-radio-button>
                </a-radio-group>
              </div>
            </div>
            <!-- 手动选择 -->
            <div v-if="curItem.params.source === 'choice'" class="block-box">
              <div class="block-title">选择优惠券 ({{ curItem.data.length }})</div>
              <SCoupon v-model="curItem.data" />
            </div>
            <!-- 自动获取 -->
            <div v-if="curItem.params.source === 'auto'" class="block-box">
              <div class="block-title">优惠券内容</div>
              <div class="block-item">
                <span class="label">显示数量</span>
                <div class="block-item-right">
                  <a-input-number v-model="curItem.params.showNum" :min="1" :max="15" />
                  <span class="unit-text">
                    <span>个</span>
                  </span>
                </div>
              </div>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingTop" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingTop }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">优惠券样式</div>
              <div class="block-item">
                <span class="label">左右间距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.marginRight" :min="0" :max="40" />
                  <span class="unit-text">
                    <span>{{ curItem.style.marginRight }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">优惠券背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'couponBgColor', '#ffa708')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.couponBgColor" />
                </div>
              </div>
              <div class="block-item">
                <span class="label">优惠券文字颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'couponTextColor', '#ffffff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.couponTextColor" />
                </div>
              </div>
              <div class="block-item">
                <span class="label">领取按钮颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'receiveBgColor', '#717070')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.receiveBgColor" />
                </div>
              </div>
              <div class="block-item">
                <span class="label">领取文字颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'receiveTextColor', '#ffffff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.receiveTextColor" />
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 头条快报 -->
      <div v-if="curItem.type == 'special'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="block-box">
              <div class="block-title">内容展示</div>
              <div class="block-item flex flex-y-center">
                <span class="label">头条图片</span>
                <span class="tips-wrap">建议尺寸：140 × 38</span>
                <SImage v-model="curItem.params.image" :width="60" :height="60" />
              </div>
              <div class="block-item">
                <span class="label">显示行数</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.params.display">
                  <a-radio-button :value="1">单行</a-radio-button>
                  <a-radio-button :value="2">双行</a-radio-button>
                </a-radio-group>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">数据来源</div>
              <div class="block-item">
                <span class="label">文章分类</span>
                <SArticleCate v-model="curItem.params.auto.category" />
              </div>
              <div class="block-item">
                <span class="label">显示数量</span>
                <div class="block-item-right">
                  <a-input-number v-model="curItem.params.auto.showNum" :min="1" :max="20" />
                  <span class="unit-text">
                    <span>篇</span>
                  </span>
                </div>
              </div>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">内容样式</div>
              <div class="block-item">
                <span class="label">文字颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'textColor', '#000')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.textColor" />
                </div>
              </div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingTop" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingTop }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 备案号 -->
      <div v-if="curItem.type == 'ICPLicense'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="sub-title">网站备案号请放到页面最底部，仅在H5端显示</div>
            <div class="block-box">
              <div class="block-title">文字内容</div>
              <div class="block-item">
                <span class="label">内容</span>
                <a-input v-model="curItem.params.text" />
              </div>
              <div class="block-item mb-15">
                <span class="label">链接</span>
                <a-input v-model="curItem.params.link" />
              </div>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">内容样式</div>
              <div class="block-item">
                <span class="label">文字大小</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.fontSize" :min="12" :max="24" />
                  <span class="unit-text">
                    <span>{{ curItem.style.fontSize }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">文字对齐</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.style.textAlign">
                  <a-radio-button value="left">居左</a-radio-button>
                  <a-radio-button value="center">居中</a-radio-button>
                  <a-radio-button value="right">居右</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item">
                <span class="label">文字颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'textColor', '#696969')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.textColor" />
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingTop" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingTop }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">左右边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingLeft" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingLeft }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>

      <!-- 标题文本 -->
      <div v-if="curItem.type == 'title'" class="editor-content">
        <a-tabs>
          <a-tab-pane key="1" tab="内容设置">
            <div class="block-box">
              <div class="block-title">标题内容</div>
              <div class="block-item">
                <span class="label">标题文字</span>
                <a-input v-model="curItem.params.title" />
              </div>
              <div class="block-item">
                <span class="label">标题大小</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.params.titleFontSize" :min="12" :max="18" />
                  <span class="unit-text">
                    <span>{{ curItem.params.titleFontSize }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">标题字体</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.params.titleFontWeight">
                  <a-radio-button value="normal">常规</a-radio-button>
                  <a-radio-button value="bold">加粗</a-radio-button>
                </a-radio-group>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">描述内容</div>
              <div class="block-item">
                <span class="label">描述文字</span>
                <a-input v-model="curItem.params.desc" />
              </div>
              <div class="block-item">
                <span class="label">描述大小</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.params.descFontSize" :min="12" :max="16" />
                  <span class="unit-text">
                    <span>{{ curItem.params.descFontSize }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">描述字体</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.params.descFontWeight">
                  <a-radio-button value="normal">常规</a-radio-button>
                  <a-radio-button value="bold">加粗</a-radio-button>
                </a-radio-group>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">查看更多</div>
              <div class="block-item">
                <span class="label">是否显示</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.params.more.enable">
                  <a-radio-button :value="true">显示</a-radio-button>
                  <a-radio-button :value="false">隐藏</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item">
                <span class="label">文字内容</span>
                <a-input v-model="curItem.params.more.text" />
              </div>
              <div class="block-item">
                <span class="label">箭头图标</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.params.more.enableIcon">
                  <a-radio-button :value="true">显示</a-radio-button>
                  <a-radio-button :value="false">隐藏</a-radio-button>
                </a-radio-group>
              </div>
              <div class="block-item">
                <span class="label">跳转链接</span>
                <SLink v-model="curItem.params.more.link" textAlign="right" />
              </div>
            </div>
          </a-tab-pane>
          <a-tab-pane key="2" tab="样式设置">
            <div class="block-box">
              <div class="block-title">内容样式</div>
              <!-- <div class="block-item">
                <span class="label">显示位置</span>
                <a-radio-group buttonStyle="solid" v-model="curItem.style.textAlign">
                  <a-radio-button value="left">居左</a-radio-button>
                  <a-radio-button value="center">居中</a-radio-button>
                </a-radio-group>
              </div>-->
              <div class="block-item">
                <span class="label">标题文字颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'titleTextColor', '#323233')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.titleTextColor" />
                </div>
              </div>
              <div class="block-item">
                <span class="label">描述文字颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'descTextColor', '#969799')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.descTextColor" />
                </div>
              </div>
              <div class="block-item">
                <span class="label">更多文字颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'moreTextColor', '#969799')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.moreTextColor" />
                </div>
              </div>
            </div>
            <div class="block-box">
              <div class="block-title">组件样式</div>
              <div class="block-item">
                <span class="label">上下边距</span>
                <div class="item-slider">
                  <a-slider v-model="curItem.style.paddingY" :min="0" :max="50" />
                  <span class="unit-text">
                    <span>{{ curItem.style.paddingY }}</span>
                    <span>像素</span>
                  </span>
                </div>
              </div>
              <div class="block-item">
                <span class="label">背景颜色</span>
                <div class="item-colorPicker">
                  <span
                    class="rest-color"
                    @click="onEditorResetColor(curItem.style, 'background', '#fff')"
                  >重置</span>
                  <MyColorPicker v-model="curItem.style.background" />
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>
    </template>
  </div>
</template>

<script>
import Vue from 'vue'
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import draggable from 'vuedraggable'
import { inArray } from '@/utils/util'
import { Ueditor, SelectCategory } from '@/components'
import { SImage, SArticleCate, SGoods, SLink, SHotZone, SCoupon } from './modules'

export default {
  props: {
    // 页面装修默认数据
    defaultData: PropTypes.object.def({}),
    // 页面数据
    data: PropTypes.object.def({}),
    // 当前选中的元素
    curItem: PropTypes.object.def({}),
    // 当前选中的元素索引
    selectedIndex: PropTypes.oneOfType([PropTypes.number, PropTypes.string]).def(0)
  },
  components: {
    draggable,
    Ueditor,
    SImage,
    SArticleCate,
    SGoods,
    SelectCategory,
    SLink,
    SHotZone,
    SCoupon
  },
  data () {
    return {}
  },
  beforeCreate () {
    this.inArray = inArray
  },
  methods: {

    // 新增数据
    handleAddData (max = 1) {
      const { defaultData, curItem } = this
      const newDataItem = defaultData.items[curItem.type].data[0]
      curItem.data.push(_.cloneDeep(newDataItem))
    },

    /**
     * 编辑器：删除data元素
     * @param curItem
     * @param index
     */
    handleDeleleData (curItem, index) {
      if (curItem.data.length <= 1) {
        this.$message.warning('至少保留一个')
        return false
      }
      curItem.data.splice(index, 1)
    },

    /**
     * 重置颜色
     * @param holder
     * @param attribute
     * @param color
     */
    onEditorResetColor (holder, attribute, color) {
      holder[attribute] = color
    }

  }
}
</script>
<style lang="less" scoped>
@import './style.less';
</style>
